<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Client;
use App\Models\Organization;
use App\Models\TreeAssessment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Home del portale pubblico: numeri del patrimonio, ricerca per etichetta e
 * scheda dell'elemento.
 */
class PortaleRicercaTest extends TestCase
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
        $this->area = $this->createArea($this->organization, ['name' => 'Parco Cinque Pini']);
        $this->client = Client::withoutGlobalScopes()->where('tenant_id', $this->organization->id)->firstOrFail();
        $this->client->forceFill([
            'name' => 'Comune di Mentana',
            'public_slug' => 'mentana',
            'public_enabled' => true,
            'label_prefix' => 'MEN',
            'public_profile' => ['display_name' => 'Comune di Mentana'],
        ])->save();
        $this->type = $this->makeObjectType($this->organization, 'P', 'P103108');
        $this->actingAsTenantUser($this->user);
    }

    private function creaAlbero(array $albero = [], array $asset = []): string
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry(),
            'surveyed_at' => '2026-05-12',
            ...$asset,
        ])->assertCreated()->json('data.id');

        if ($albero !== []) {
            $this->patchJson("/api/v1/assets/{$id}", ['tree' => $albero])->assertOk();
        }

        return $id;
    }

    public function test_la_home_mostra_i_numeri_del_patrimonio(): void
    {
        $this->creaAlbero(['genus' => 'Cupressus', 'species' => 'Cupressus sempervirens', 'common_name' => 'Cipresso']);
        $this->creaAlbero(['genus' => 'Tilia', 'species' => 'Tilia cordata']);
        $this->creaAlbero(['genus' => 'Tilia', 'species' => 'Tilia cordata']);

        $risposta = $this->get('/comune/mentana');

        $risposta->assertOk();
        $risposta->assertSee('Elementi censiti');
        $risposta->assertSee('Varietà botaniche', false);
        // Tre alberi, due varietà distinte
        $risposta->assertSeeInOrder(['3', 'Elementi censiti', '3', 'Alberi', '2', 'Varietà botaniche'], false);
    }

    public function test_l_elemento_nascosto_non_entra_nei_numeri(): void
    {
        $id = $this->creaAlbero(['species' => 'Tilia cordata']);
        $this->creaAlbero(['species' => 'Acer campestre']);

        $this->patchJson("/api/v1/assets/{$id}", ['public_hidden' => true])->assertOk();

        $this->get('/comune/mentana')->assertOk()->assertSeeInOrder(['1', 'Elementi censiti'], false);
    }

    public function test_la_ricerca_accetta_il_numero_senza_prefisso(): void
    {
        $this->creaAlbero(['common_name' => 'Cipresso']);

        $this->get('/comune/mentana/cerca?etichetta=1')
            ->assertRedirect('/comune/mentana/elemento/MEN-0001');

        $this->get('/comune/mentana/cerca?etichetta=MEN-0001')
            ->assertRedirect('/comune/mentana/elemento/MEN-0001');
    }

    public function test_la_ricerca_senza_esito_lo_dice_senza_rivelare_nulla(): void
    {
        $this->creaAlbero();

        $risposta = $this->get('/comune/mentana/cerca?etichetta=9999');

        $risposta->assertOk();
        $risposta->assertSee('Nessun elemento trovato');
    }

    public function test_la_ricerca_non_trova_gli_elementi_di_un_altro_committente(): void
    {
        $this->creaAlbero();

        // Secondo committente della stessa impresa, con il suo portale
        $altraArea = $this->createArea($this->organization, ['name' => 'Area Guidonia']);
        $altro = Client::withoutGlobalScopes()->findOrFail($altraArea->locality->site->client_id);
        $altro->forceFill([
            'name' => 'Comune di Guidonia', 'public_slug' => 'guidonia',
            'public_enabled' => true, 'label_prefix' => 'GUI',
        ])->save();

        $this->postJson('/api/v1/assets', [
            'area_id' => $altraArea->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated();

        // Il codice di Guidonia non si apre dal portale di Mentana
        $this->get('/comune/mentana/cerca?etichetta=GUI-0001')->assertOk()->assertSee('Nessun elemento trovato');
        $this->get('/comune/mentana/elemento/GUI-0001')->assertNotFound();

        $this->get('/comune/guidonia/cerca?etichetta=GUI-0001')
            ->assertRedirect('/comune/guidonia/elemento/GUI-0001');
    }

    public function test_la_scheda_mostra_i_dati_divulgativi_e_non_le_note(): void
    {
        $this->creaAlbero([
            'genus' => 'Cupressus', 'species' => 'Cupressus sempervirens',
            'common_name' => 'Cipresso', 'dbh_cm' => 30, 'height_m' => 6.5,
        ], ['notes' => 'NOTA INTERNA RISERVATA']);

        $risposta = $this->get('/comune/mentana/elemento/MEN-0001');

        $risposta->assertOk();
        $risposta->assertSee('MEN-0001');
        $risposta->assertSee('Cupressus sempervirens');
        $risposta->assertSee('Cipresso');
        $risposta->assertSee('12/05/2026');
        $risposta->assertSee('Parco Cinque Pini');
        $risposta->assertSee('30 cm');
        $risposta->assertSee('6,5 m');
        $risposta->assertDontSee('NOTA INTERNA RISERVATA');
    }

    public function test_la_scheda_di_un_elemento_nascosto_o_abbattuto_non_si_apre(): void
    {
        $nascosto = $this->creaAlbero();
        $abbattuto = $this->creaAlbero();

        $this->patchJson("/api/v1/assets/{$nascosto}", ['public_hidden' => true])->assertOk();
        $this->postJson("/api/v1/assets/{$abbattuto}/removal", [
            'removed_on' => now('Europe/Rome')->toDateString(),
            'removal_reason' => 'Instabilità conclamata',
        ])->assertOk();

        $this->get('/comune/mentana/elemento/MEN-0001')->assertNotFound();
        $this->get('/comune/mentana/elemento/MEN-0002')->assertNotFound();
    }

    public function test_la_scheda_si_apre_anche_su_aiuole_e_siepi(): void
    {
        // Non tutti gli elementi censiti sono punti: le coordinate di
        // un'area sono quelle di un punto rappresentativo
        $tipoArea = $this->makeObjectType($this->organization, 'S', 'S303101');

        $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $tipoArea->id,
            'geometry' => $this->squarePolygon(),
        ])->assertCreated();

        $this->get('/comune/mentana/elemento/MEN-0001')->assertOk()->assertSee('Coordinate');
    }

    public function test_lo_stato_pubblico_segue_la_valutazione_di_stabilita(): void
    {
        $sano = $this->creaAlbero();
        $daVerificare = $this->creaAlbero();

        TreeAssessment::withoutGlobalScopes()->create([
            'tenant_id' => $this->organization->id,
            'tree_id' => $daVerificare,
            'assessment_type' => 'vta_visual',
            'assessed_on' => now('Europe/Rome')->toDateString(),
            'failure_class' => 'D',
            'outcome' => 'fell',
        ]);

        $this->get('/comune/mentana/elemento/MEN-0001')->assertOk()->assertSee('Sano');
        // In pubblico non si scrive "da abbattere" prima della decisione dell'ente
        $risposta = $this->get('/comune/mentana/elemento/MEN-0002');
        $risposta->assertOk()->assertSee('In verifica');
        $risposta->assertDontSee('abbattere');

        $this->assertNotEmpty($sano);
    }
}
