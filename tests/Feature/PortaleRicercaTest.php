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

    public function test_i_numeri_a_zero_non_compaiono(): void
    {
        $this->creaAlbero(['species' => 'Tilia cordata']);

        $risposta = $this->get('/comune/mentana');

        $risposta->assertOk();
        // Senza interventi registrati, "0 alberi curati" non informa:
        // sembra una mancanza dell'ente invece di un dato non ancora raccolto
        $risposta->assertDontSee('Alberi curati');
        $risposta->assertDontSee('Albero curato');
        $risposta->assertDontSee('Alberi potati');
    }

    public function test_la_home_mette_in_evidenza_la_ricerca_del_cartellino(): void
    {
        $this->creaAlbero(['species' => 'Tilia cordata']);

        $this->get('/comune/mentana')
            ->assertOk()
            ->assertSee('Numero del cartellino')
            ->assertSee("Cerca l'albero", false);
    }

    public function test_la_home_mostra_l_anteprima_della_mappa(): void
    {
        $this->creaAlbero(['species' => 'Tilia cordata']);

        $risposta = $this->get('/comune/mentana');

        $risposta->assertOk();
        $risposta->assertSee('anteprima-mappa', false);
        $risposta->assertSee('Apri la mappa', false);
        // L'anteprima si guarda, non si manovra
        $risposta->assertSee('"anteprima":true', false);
    }

    public function test_l_elemento_nascosto_non_entra_nei_numeri(): void
    {
        $id = $this->creaAlbero(['species' => 'Tilia cordata']);
        $this->creaAlbero(['species' => 'Acer campestre']);

        $this->patchJson("/api/v1/assets/{$id}", ['public_hidden' => true])->assertOk();

        // Con un solo elemento l'etichetta va al singolare
        $this->get('/comune/mentana')->assertOk()->assertSeeInOrder(['1', 'Elemento censito'], false);
    }

    public function test_la_ricerca_accetta_il_numero_senza_prefisso(): void
    {
        $this->creaAlbero(['common_name' => 'Cipresso']);

        $this->get('/comune/mentana/cerca?etichetta=1')
            ->assertRedirect('/comune/mentana/elemento/MEN-0001');

        $this->get('/comune/mentana/cerca?etichetta=MEN-0001')
            ->assertRedirect('/comune/mentana/elemento/MEN-0001');
    }

    public function test_la_ricerca_del_portale_resta_a_corrispondenza_esatta(): void
    {
        $this->creaAlbero();

        // Sul portale si copia il numero letto sul cartellino: niente ricerca
        // a pezzi del gestionale (un pezzo di codice non apre nulla) e niente
        // tolleranza sugli accenti, il codice o e' quello o non e' lui
        $this->get('/comune/mentana/cerca?etichetta=MEN')
            ->assertOk()->assertSee('Nessun elemento trovato');
        $this->get('/comune/mentana/cerca?etichetta=EN-0001')
            ->assertOk()->assertSee('Nessun elemento trovato');
        $this->get('/comune/mentana/cerca?etichetta='.urlencode('MÈN-0001'))
            ->assertOk()->assertSee('Nessun elemento trovato');

        // Il codice esatto invece apre la scheda, come sempre
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
        // Il genere non va anteposto a una specie che è già un binomio
        $risposta->assertSee('<em>Cupressus sempervirens</em>', false);
        $risposta->assertDontSee('Cupressus Cupressus');
        $risposta->assertSee('Cipresso');
        $risposta->assertSee('12/05/2026');
        $risposta->assertSee('Parco Cinque Pini');
        $risposta->assertSee('30 cm');
        $risposta->assertSee('6,5 m');
        $risposta->assertDontSee('NOTA INTERNA RISERVATA');
    }

    public function test_la_scheda_di_un_elemento_nascosto_abbattuto_o_dismesso_non_si_apre(): void
    {
        $nascosto = $this->creaAlbero();
        $abbattuto = $this->creaAlbero();
        $dismesso = $this->creaAlbero();

        $this->patchJson("/api/v1/assets/{$nascosto}", ['public_hidden' => true])->assertOk();
        $this->postJson("/api/v1/assets/{$abbattuto}/removal", [
            'removed_on' => now('Europe/Rome')->toDateString(),
            'removal_reason' => 'Instabilità conclamata',
        ])->assertOk();
        // L'archivio del censimento non cambia il portale: PortalQuery tiene
        // solo gli attivi, e un dismesso non deve comparire in vetrina
        $this->patchJson("/api/v1/assets/{$dismesso}", ['status' => 'dismissed'])->assertOk();

        $this->get('/comune/mentana/elemento/MEN-0001')->assertNotFound();
        $this->get('/comune/mentana/elemento/MEN-0002')->assertNotFound();
        $this->get('/comune/mentana/elemento/MEN-0003')->assertNotFound();
    }

    public function test_la_scheda_offre_navigazione_e_segnalazione(): void
    {
        $this->creaAlbero();

        // Senza indirizzo mail dell'ente il pulsante di segnalazione non compare
        $this->get('/comune/mentana/elemento/MEN-0001')
            ->assertOk()
            // L'apostrofo nel testo va confrontato senza rifugio HTML
            ->assertSee("Raggiungi l'elemento", false)
            ->assertDontSee('Segnala un problema');

        $this->client->forceFill(['public_profile' => [
            'display_name' => 'Comune di Mentana',
            'contact_email' => 'verde@comune.mentana.it',
        ]])->save();

        $risposta = $this->get('/comune/mentana/elemento/MEN-0001');
        $risposta->assertOk();
        $risposta->assertSee('Segnala un problema');
        $risposta->assertSee('mailto:verde@comune.mentana.it', false);
        // Le coordinate portano alla posizione su una mappa
        $risposta->assertSee('45.465200', false);
        $risposta->assertSee('openstreetmap.org', false);
    }

    public function test_la_foto_pubblica_si_puo_ingrandire(): void
    {
        $id = $this->creaAlbero();

        $this->post("/api/v1/assets/{$id}/photos", [
            'photo' => \Illuminate\Http\UploadedFile::fake()->image('albero.jpg', 800, 600),
            'category' => 'census',
        ])->assertCreated();

        $this->get('/comune/mentana/elemento/MEN-0001')
            ->assertOk()
            ->assertSee('data-ingrandisci="', false);

        $foto = $this->get('/comune/mentana/elemento/MEN-0001/foto');
        $foto->assertOk();
        $this->assertSame('image/jpeg', $foto->headers->get('Content-Type'));
    }

    public function test_la_foto_di_un_difetto_non_finisce_in_vetrina(): void
    {
        $id = $this->creaAlbero();

        $this->post("/api/v1/assets/{$id}/photos", [
            'photo' => \Illuminate\Http\UploadedFile::fake()->image('difetto.jpg', 400, 300),
            'category' => 'defect',
        ])->assertCreated();

        $this->get('/comune/mentana/elemento/MEN-0001')->assertOk()->assertDontSee('data-ingrandisci="', false);
        $this->get('/comune/mentana/elemento/MEN-0001/foto')->assertNotFound();
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
