<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Vincoli del territorio: anagrafica, collegamento agli elementi (a mano o
 * per perimetro), documento scaricabile dal portale.
 */
class VincoliTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Organization $organization;

    private $user;

    private Area $area;

    private Client $client;

    private $type;

    private string $assetId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        [$this->organization, $this->user] = $this->createTenantUser();
        $this->area = $this->createArea($this->organization);
        $this->client = Client::withoutGlobalScopes()->where('tenant_id', $this->organization->id)->firstOrFail();
        $this->client->forceFill([
            'public_slug' => 'mentana', 'public_enabled' => true, 'label_prefix' => 'MEN',
        ])->save();
        $this->type = $this->makeObjectType($this->organization, 'P', 'P103108');
        $this->actingAsTenantUser($this->user);

        $this->assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
    }

    private function creaVincolo(array $dati = []): string
    {
        return $this->postJson('/api/v1/constraints', [
            'client_id' => $this->client->id,
            'code' => 'art.28 PTPR',
            'name' => 'Aree urbanizzate',
            'authority' => 'Regione Lazio',
            ...$dati,
        ])->assertCreated()->json('data.id');
    }

    public function test_il_vincolo_si_crea_e_si_collega_a_mano(): void
    {
        $vincolo = $this->creaVincolo();

        $this->postJson("/api/v1/assets/{$this->assetId}/constraints", ['constraint_id' => $vincolo])
            ->assertOk()
            ->assertJsonPath('data.0.code', 'art.28 PTPR')
            ->assertJsonPath('data.0.source', 'manual');

        // Ricollegare lo stesso vincolo non crea doppioni
        $this->postJson("/api/v1/assets/{$this->assetId}/constraints", ['constraint_id' => $vincolo])
            ->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/assets/{$this->assetId}/constraints/{$vincolo}")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_il_perimetro_collega_da_solo_chi_ci_ricade(): void
    {
        // Il perimetro copre l'area di prova, quindi anche l'elemento
        $vincolo = $this->creaVincolo(['geometry' => $this->squarePolygon()]);

        $risposta = $this->postJson("/api/v1/constraints/{$vincolo}/ricalcola")->assertOk();
        $this->assertSame(1, $risposta->json('data.collegati'));

        $this->getJson("/api/v1/assets/{$this->assetId}/constraints")
            ->assertOk()->assertJsonPath('data.0.source', 'spatial');
    }

    public function test_il_ricalcolo_non_tocca_i_collegamenti_fatti_a_mano(): void
    {
        // Vincolo con perimetro lontano dall'elemento
        $vincolo = $this->creaVincolo(['geometry' => [
            'type' => 'Polygon',
            'coordinates' => [[[12.0, 41.0], [12.001, 41.0], [12.001, 41.001], [12.0, 41.001], [12.0, 41.0]]],
        ]]);

        $this->postJson("/api/v1/assets/{$this->assetId}/constraints", ['constraint_id' => $vincolo])->assertOk();

        $this->postJson("/api/v1/constraints/{$vincolo}/ricalcola")
            ->assertOk()->assertJsonPath('data.collegati', 0);

        // Il collegamento a mano è ancora lì
        $this->getJson("/api/v1/assets/{$this->assetId}/constraints")
            ->assertOk()->assertJsonPath('data.0.source', 'manual');
    }

    public function test_senza_perimetro_il_ricalcolo_lo_dice(): void
    {
        $vincolo = $this->creaVincolo();

        $this->postJson("/api/v1/constraints/{$vincolo}/ricalcola")
            ->assertUnprocessable()->assertJsonValidationErrors('geometry');
    }

    public function test_il_vincolo_compare_sulla_scheda_pubblica_con_il_documento(): void
    {
        $vincolo = $this->creaVincolo();
        $this->postJson("/api/v1/assets/{$this->assetId}/constraints", ['constraint_id' => $vincolo])->assertOk();

        // Senza documento compare comunque, ma senza collegamento
        $this->get('/comune/mentana/elemento/MEN-0001')
            ->assertOk()
            ->assertSee('art.28 PTPR')
            ->assertSee('Aree urbanizzate')
            ->assertDontSee("/vincolo/{$vincolo}/documento", false);

        $this->post("/api/v1/constraints/{$vincolo}/documento", [
            'documento' => UploadedFile::fake()->create('art28.pdf', 40, 'application/pdf'),
        ])->assertOk();

        $this->get('/comune/mentana/elemento/MEN-0001')
            ->assertOk()
            ->assertSee("/vincolo/{$vincolo}/documento", false);

        $documento = $this->get("/comune/mentana/vincolo/{$vincolo}/documento");
        $documento->assertOk();
        $this->assertSame('application/pdf', $documento->headers->get('Content-Type'));
    }

    public function test_il_vincolo_non_pubblico_resta_nel_gestionale(): void
    {
        $vincolo = $this->creaVincolo(['is_public' => false]);
        $this->postJson("/api/v1/assets/{$this->assetId}/constraints", ['constraint_id' => $vincolo])->assertOk();

        $this->get('/comune/mentana/elemento/MEN-0001')->assertOk()->assertDontSee('art.28 PTPR');
        $this->get("/comune/mentana/vincolo/{$vincolo}/documento")->assertNotFound();
    }

    public function test_il_vincolo_di_un_altro_committente_non_esce_qui(): void
    {
        $altraArea = $this->createArea($this->organization, ['name' => 'Area Guidonia']);
        $altro = Client::withoutGlobalScopes()->findOrFail($altraArea->locality->site->client_id);
        $altro->forceFill(['public_slug' => 'guidonia', 'public_enabled' => true])->save();

        $vincolo = $this->creaVincolo(['client_id' => $altro->id, 'code' => 'Vincolo Guidonia']);
        $this->postJson("/api/v1/assets/{$this->assetId}/constraints", ['constraint_id' => $vincolo])->assertOk();

        // L'elemento è di Mentana: un vincolo intestato a Guidonia non compare
        $this->get('/comune/mentana/elemento/MEN-0001')->assertOk()->assertDontSee('Vincolo Guidonia');
        $this->get("/comune/mentana/vincolo/{$vincolo}/documento")->assertNotFound();
    }

    public function test_il_documento_sostituito_non_resta_sul_disco(): void
    {
        $vincolo = $this->creaVincolo();

        $this->post("/api/v1/constraints/{$vincolo}/documento", [
            'documento' => UploadedFile::fake()->create('primo.pdf', 20, 'application/pdf'),
        ])->assertOk();

        $primo = \App\Models\Document::withoutGlobalScopes()->firstOrFail();
        $percorso = $primo->getRawOriginal('s3_key');
        Storage::disk()->assertExists($percorso);

        $this->post("/api/v1/constraints/{$vincolo}/documento", [
            'documento' => UploadedFile::fake()->create('secondo.pdf', 20, 'application/pdf'),
        ])->assertOk();

        Storage::disk()->assertMissing($percorso);
    }
}
