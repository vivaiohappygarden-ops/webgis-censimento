<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Fondamenta del portale pubblico: riconoscimento del committente
 * dall'indirizzo, numerazione delle etichette con prefisso, esclusione del
 * singolo elemento dalla vetrina.
 */
class PortalePubblicoTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Organization $organization;

    private $user;

    private Area $area;

    private Client $client;

    private $type;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        [$this->organization, $this->user] = $this->createTenantUser();
        $this->area = $this->createArea($this->organization);
        $this->client = Client::withoutGlobalScopes()->where('tenant_id', $this->organization->id)->firstOrFail();
        $this->type = $this->makeObjectType($this->organization, 'P', 'P103108');
        $this->actingAsTenantUser($this->user);
    }

    private function accendiPortale(array $profilo = []): void
    {
        $this->client->forceFill([
            'public_slug' => 'mentana',
            'public_enabled' => true,
            'public_profile' => ['display_name' => 'Comune di Mentana', ...$profilo],
        ])->save();
    }

    public function test_il_portale_e_spento_finche_non_lo_si_accende(): void
    {
        $this->client->forceFill(['public_slug' => 'mentana'])->save();

        $this->get('/comune/mentana')->assertNotFound();
    }

    public function test_il_portale_acceso_mostra_l_identita_del_comune(): void
    {
        $this->accendiPortale([
            'welcome_text' => 'Benvenuti nel censimento del verde cittadino.',
            'color' => '#7f1d1d',
            'contact_email' => 'verde@comune.mentana.it',
            'footer_text' => 'Servizio Ambiente - Comune di Mentana',
        ]);

        $risposta = $this->get('/comune/mentana');

        $risposta->assertOk();
        $risposta->assertSee('Comune di Mentana');
        $risposta->assertSee('Benvenuti nel censimento del verde cittadino.');
        $risposta->assertSee('#7f1d1d', false);
        $risposta->assertSee('verde@comune.mentana.it');
        $risposta->assertSee('Servizio Ambiente - Comune di Mentana');
        // Un sito civico non deve depositare cookie di sessione
        $this->assertEmpty($risposta->headers->getCookies());
    }

    public function test_il_portale_si_spegne_con_l_organizzazione_disattivata(): void
    {
        $this->accendiPortale();
        $this->get('/comune/mentana')->assertOk();

        $this->organization->forceFill(['is_active' => false])->save();

        $this->get('/comune/mentana')->assertNotFound();
    }

    public function test_il_portale_si_spegne_con_il_committente_disattivato(): void
    {
        $this->accendiPortale();
        $this->client->forceFill(['is_active' => false])->save();

        $this->get('/comune/mentana')->assertNotFound();
    }

    public function test_un_indirizzo_di_un_altro_committente_non_apre_questo_portale(): void
    {
        $this->accendiPortale();

        // Secondo committente, di un'altra impresa: deve restare separato
        [$altraOrg] = $this->createTenantUser();
        $altro = Client::withoutGlobalScopes()->where('tenant_id', $altraOrg->id)->first()
            ?? Client::create(['tenant_id' => $altraOrg->id, 'name' => 'Comune di Guidonia', 'client_type' => 'public']);
        $altro->forceFill(['public_slug' => 'guidonia', 'public_enabled' => true])->save();

        $this->get('/comune/guidonia')->assertOk()->assertDontSee('Comune di Mentana');
        $this->get('/comune/inesistente')->assertNotFound();
    }

    public function test_lo_stemma_viene_servito_ricodificato(): void
    {
        $this->accendiPortale();

        $this->post("/api/v1/clients/{$this->client->id}/stemma", [
            'stemma' => UploadedFile::fake()->image('stemma.jpg', 600, 600),
        ])->assertOk()->assertJsonPath('data.has_logo', true);

        $risposta = $this->get('/comune/mentana/stemma');
        $risposta->assertOk();
        $this->assertSame('image/png', $risposta->headers->get('Content-Type'));

        $this->delete("/api/v1/clients/{$this->client->id}/stemma")
            ->assertOk()->assertJsonPath('data.has_logo', false);
        $this->get('/comune/mentana/stemma')->assertNotFound();
    }

    public function test_indirizzo_riservato_o_gia_usato_viene_rifiutato(): void
    {
        $this->patchJson("/api/v1/clients/{$this->client->id}", ['public_slug' => 'www'])
            ->assertUnprocessable()->assertJsonValidationErrors('public_slug');

        $this->patchJson("/api/v1/clients/{$this->client->id}", ['public_slug' => 'Mentana!'])
            ->assertUnprocessable()->assertJsonValidationErrors('public_slug');

        $this->client->forceFill(['public_slug' => 'mentana'])->save();

        $secondo = $this->postJson('/api/v1/clients', [
            'name' => 'Comune di Guidonia', 'client_type' => 'public',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/clients/{$secondo}", ['public_slug' => 'mentana'])
            ->assertUnprocessable()->assertJsonValidationErrors('public_slug');
    }

    public function test_accendere_il_portale_senza_indirizzo_lo_ricava_dal_nome(): void
    {
        $this->client->forceFill(['name' => 'Comune di Sant\'Angelo Romano'])->save();

        $risposta = $this->patchJson("/api/v1/clients/{$this->client->id}", ['public_enabled' => true])
            ->assertOk();

        $this->assertSame('sant-angelo-romano', $risposta->json('data.public_slug'));
        $this->get('/comune/sant-angelo-romano')->assertOk();
    }

    public function test_il_prefisso_viene_proposto_alla_creazione_del_committente(): void
    {
        $dati = $this->postJson('/api/v1/clients', [
            'name' => 'Comune di Mentana', 'client_type' => 'public',
        ])->assertCreated()->json('data');

        $this->assertSame('MEN', $dati['label_prefix']);
    }

    public function test_la_numerazione_riparte_da_uno_per_ogni_committente(): void
    {
        $this->client->forceFill(['label_prefix' => 'MEN'])->save();

        $codici = collect(range(1, 3))->map(fn () => $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.census_code'));

        $this->assertSame(['MEN-0001', 'MEN-0002', 'MEN-0003'], $codici->all());

        // Un secondo committente riparte da uno con il proprio prefisso
        $altraArea = $this->createArea($this->organization, ['name' => 'Area Guidonia']);
        Client::withoutGlobalScopes()->whereKey($altraArea->locality->site->client_id)
            ->update(['label_prefix' => 'GUI']);

        $this->assertSame('GUI-0001', $this->postJson('/api/v1/assets', [
            'area_id' => $altraArea->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.census_code'));
    }

    public function test_il_numero_di_un_elemento_eliminato_non_viene_riassegnato(): void
    {
        $this->client->forceFill(['label_prefix' => 'MEN'])->save();

        $primo = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/assets/{$primo}")->assertNoContent();

        // Il cartellino MEN-0001 può essere già stato applicato in campo
        $this->assertSame('MEN-0002', $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.census_code'));
    }

    public function test_un_codice_scritto_a_mano_ha_la_precedenza(): void
    {
        $this->client->forceFill(['label_prefix' => 'MEN'])->save();

        $this->assertSame('OBJ-CAM-77', $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->type->id,
            'census_code' => 'OBJ-CAM-77',
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.census_code'));
    }

    public function test_l_elemento_si_puo_nascondere_dalla_vetrina(): void
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        $this->assertFalse((bool) Asset::withoutGlobalScopes()->findOrFail($id)->public_hidden);

        $this->patchJson("/api/v1/assets/{$id}", ['public_hidden' => true])->assertOk();

        $this->assertTrue((bool) Asset::withoutGlobalScopes()->findOrFail($id)->public_hidden);
    }

    public function test_la_rinumerazione_e_una_variante_da_confermare(): void
    {
        $this->client->forceFill(['label_prefix' => 'MEN'])->save();

        foreach (['VECCHIO-9', 'VECCHIO-4'] as $codice) {
            $this->postJson('/api/v1/assets', [
                'area_id' => $this->area->id,
                'object_type_id' => $this->type->id,
                'census_code' => $codice,
                'geometry' => $this->pointGeometry(),
            ])->assertCreated();
        }

        // Prova a vuoto: non tocca nulla
        $this->artisan('censimento:rinumera', ['committente' => $this->client->name])
            ->assertSuccessful();
        $this->assertSame(2, Asset::withoutGlobalScopes()
            ->whereIn('census_code', ['VECCHIO-9', 'VECCHIO-4'])->count());

        $this->artisan('censimento:rinumera', ['committente' => $this->client->name, '--conferma' => true])
            ->assertSuccessful();

        // Stesso ordinamento del comando (id come spareggio): due inserimenti
        // nello stesso istante non devono rendere ambiguo chi è MEN-0001
        $codici = Asset::withoutGlobalScopes()->orderBy('created_at')->orderBy('id')->pluck('census_code')->all();
        $this->assertSame(['MEN-0001', 'MEN-0002'], $codici);

        // Il codice precedente resta scritto sulla scheda
        $primo = Asset::withoutGlobalScopes()->where('census_code', 'MEN-0001')->firstOrFail();
        $this->assertSame('VECCHIO-9', $primo->attributes['codice_precedente'] ?? null);

        // Il contatore non torna indietro: il prossimo elemento è il terzo
        $this->assertSame('MEN-0003', $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.census_code'));
    }
}
