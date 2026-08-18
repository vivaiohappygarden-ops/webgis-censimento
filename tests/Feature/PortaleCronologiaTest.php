<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Photo;
use App\Models\TreeAssessment;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Cronologia pubblica degli eventi e atti collegati.
 *
 * La regola che conta: niente compare in pubblico se non è stato
 * contrassegnato come pubblicabile dal backoffice.
 */
class PortaleCronologiaTest extends TestCase
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
            'name' => 'Comune di Mentana',
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

    private function creaLavoro(bool $pubblico, string $stato = 'completed'): WorkOrder
    {
        $ordine = WorkOrder::withoutGlobalScopes()->create([
            'tenant_id' => $this->organization->id,
            'code' => 'ODS-2026-0005',
            'title' => 'Eliminazione ramo secco sotto chioma',
            'description' => 'Intervento disposto a seguito di monitoraggio',
            'status' => $stato,
            'is_public' => $pubblico,
            'area_id' => $this->area->id,
        ]);
        $ordine->forceFill(['completed_at' => '2026-09-13 10:00:00'])->save();

        \App\Models\WorkOrderAsset::withoutGlobalScopes()->create([
            'tenant_id' => $this->organization->id,
            'work_order_id' => $ordine->id,
            'asset_id' => $this->assetId,
            'status' => 'done',
            'notes' => 'Rimossi rami secchi sopra il marciapiede',
        ]);

        return $ordine;
    }

    private function creaValutazione(bool $pubblica): TreeAssessment
    {
        $valutazione = TreeAssessment::withoutGlobalScopes()->create([
            'tenant_id' => $this->organization->id,
            'tree_id' => $this->assetId,
            'assessment_type' => 'vta_visual',
            'assessed_on' => '2026-05-19',
            'outcome' => 'monitor',
            'prescriptions' => 'Fusto non bilanciato, albero da monitorare annualmente',
            'next_check_due' => '2027-05-19',
            'is_public' => $pubblica,
        ]);

        // Il protocollo lo assegna il sistema alla prima stampa: non è un
        // campo che si scrive dall'esterno
        $valutazione->forceFill(['report_number' => 'PER-2026-0013'])->save();

        return $valutazione;
    }

    public function test_senza_spunta_non_compare_nulla_in_cronologia(): void
    {
        $this->creaLavoro(pubblico: false);
        $this->creaValutazione(pubblica: false);

        $risposta = $this->get('/comune/mentana/elemento/MEN-0001');

        $risposta->assertOk();
        $risposta->assertDontSee('Cronologia eventi');
        $risposta->assertDontSee('ODS-2026-0005');
        $risposta->assertDontSee('Fusto non bilanciato');
    }

    public function test_il_lavoro_pubblicato_compare_con_il_suo_atto(): void
    {
        $this->creaLavoro(pubblico: true);

        $risposta = $this->get('/comune/mentana/elemento/MEN-0001');

        $risposta->assertOk();
        $risposta->assertSee('Cronologia eventi');
        $risposta->assertSee('13/09/2026');
        $risposta->assertSee('Rimossi rami secchi sopra il marciapiede');
        $risposta->assertSee("Atti pubblici collegati all'evento", false);
        $risposta->assertSee('Ordine di servizio N. ODS-2026-0005');
        $risposta->assertSee('Comune di Mentana');
    }

    public function test_il_lavoro_non_concluso_resta_fuori(): void
    {
        $this->creaLavoro(pubblico: true, stato: 'in_progress');

        $this->get('/comune/mentana/elemento/MEN-0001')
            ->assertOk()
            ->assertDontSee('ODS-2026-0005');
    }

    public function test_la_perizia_pubblicata_mostra_prescrizioni_e_protocollo(): void
    {
        $this->creaValutazione(pubblica: true);

        $risposta = $this->get('/comune/mentana/elemento/MEN-0001');

        $risposta->assertOk();
        $risposta->assertSee('19/05/2026');
        $risposta->assertSee('VALUTAZIONE VISIVA DI STABILITÀ', false);
        $risposta->assertSee('Fusto non bilanciato');
        $risposta->assertSee('Prossima verifica prevista: 05/2027');
        $risposta->assertSee('Relazione tecnica N. PER-2026-0013');
    }

    public function test_gli_eventi_sono_in_ordine_dal_piu_recente(): void
    {
        $this->creaValutazione(pubblica: true);   // 19/05/2026
        $this->creaLavoro(pubblico: true);        // 13/09/2026

        $this->get('/comune/mentana/elemento/MEN-0001')
            ->assertOk()
            ->assertSeeInOrder(['13/09/2026', '19/05/2026']);
    }

    public function test_la_foto_dell_evento_si_vede_solo_se_l_evento_e_pubblico(): void
    {
        $ordine = $this->creaLavoro(pubblico: false);

        $fotoId = $this->post("/api/v1/assets/{$this->assetId}/photos", [
            'photo' => UploadedFile::fake()->image('intervento.jpg', 600, 400),
            'category' => 'after',
        ])->assertCreated()->json('data.id');

        Photo::withoutGlobalScopes()->whereKey($fotoId)->update([
            'subject_type' => WorkOrder::class,
            'subject_id' => $ordine->id,
        ]);

        // Lavoro non pubblicato: la foto non è raggiungibile nemmeno
        // conoscendone l'indirizzo
        $this->get("/comune/mentana/elemento/MEN-0001/foto/{$fotoId}")->assertNotFound();

        $ordine->forceFill(['is_public' => true])->save();

        $risposta = $this->get("/comune/mentana/elemento/MEN-0001/foto/{$fotoId}");
        $risposta->assertOk();
        $this->assertSame('image/jpeg', $risposta->headers->get('Content-Type'));

        $this->get('/comune/mentana/elemento/MEN-0001')
            ->assertOk()
            ->assertSee("/foto/{$fotoId}", false);
    }

    public function test_la_foto_di_un_difetto_non_esce_mai_nella_cronologia(): void
    {
        $ordine = $this->creaLavoro(pubblico: true);

        $fotoId = $this->post("/api/v1/assets/{$this->assetId}/photos", [
            'photo' => UploadedFile::fake()->image('difetto.jpg', 400, 300),
            'category' => 'defect',
        ])->assertCreated()->json('data.id');

        Photo::withoutGlobalScopes()->whereKey($fotoId)->update([
            'subject_type' => WorkOrder::class,
            'subject_id' => $ordine->id,
        ]);

        $this->get("/comune/mentana/elemento/MEN-0001/foto/{$fotoId}")->assertNotFound();
        $this->get('/comune/mentana/elemento/MEN-0001')->assertOk()->assertDontSee("/foto/{$fotoId}", false);
    }

    public function test_la_cronologia_di_un_altro_committente_non_si_mescola(): void
    {
        $this->creaLavoro(pubblico: true);

        $altraArea = $this->createArea($this->organization, ['name' => 'Area Guidonia']);
        $altro = Client::withoutGlobalScopes()->findOrFail($altraArea->locality->site->client_id);
        $altro->forceFill(['public_slug' => 'guidonia', 'public_enabled' => true, 'label_prefix' => 'GUI'])->save();

        $this->postJson('/api/v1/assets', [
            'area_id' => $altraArea->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated();

        $this->get('/comune/guidonia/elemento/GUI-0001')
            ->assertOk()
            ->assertDontSee('ODS-2026-0005');
    }

    public function test_la_foto_scattata_durante_il_lavoro_resta_agganciata_all_ordine(): void
    {
        $ordine = $this->creaLavoro(pubblico: true);

        $fotoId = $this->post("/api/v1/assets/{$this->assetId}/photos", [
            'photo' => UploadedFile::fake()->image('lavoro.jpg', 600, 400),
            'category' => 'after',
            'work_order_id' => $ordine->id,
        ])->assertCreated()->json('data.id');

        $foto = Photo::withoutGlobalScopes()->findOrFail($fotoId);
        $this->assertSame(WorkOrder::class, $foto->subject_type);
        $this->assertSame($ordine->id, $foto->subject_id);

        // Compare subito nella cronologia pubblica dell'elemento
        $this->get('/comune/mentana/elemento/MEN-0001')->assertOk()->assertSee("/foto/{$fotoId}", false);
    }

    public function test_l_interruttore_di_pubblicazione_si_comanda_dalle_api(): void
    {
        $ordine = $this->creaLavoro(pubblico: false);

        $this->patchJson("/api/v1/work-orders/{$ordine->id}", [
            'is_public' => true,
            'version' => $ordine->fresh()->version,
        ])->assertOk()->assertJsonPath('data.is_public', true);

        $this->get('/comune/mentana/elemento/MEN-0001')->assertOk()->assertSee('ODS-2026-0005');
    }
}
