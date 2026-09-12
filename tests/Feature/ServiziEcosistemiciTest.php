<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Tree;
use App\Services\Benefits\CarbonEstimate;
use App\Services\Benefits\ServiziEcosistemici;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Gli altri benefici ambientali: ossigeno, polveri sottili, pioggia.
 *
 * Stesse regole della stima CO2: il modello e i coefficienti stanno in
 * configurazione (config/benefici.php) e sono sempre dichiarati accanto al
 * numero; quel che manca non si inventa (senza età non c'è ossigeno, senza
 * chioma non ci sono polveri né pioggia); gli euro non compaiono senza un
 * prezzo e la sua fonte; in pubblico niente esce se il committente non lo
 * ha acceso.
 */
class ServiziEcosistemiciTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Organization $organizzazione;

    private $utente;

    private Area $area;

    private Client $committente;

    private $tipo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->area = $this->createArea($this->organizzazione);
        $this->committente = Client::withoutGlobalScopes()
            ->where('tenant_id', $this->organizzazione->id)->firstOrFail();
        $this->committente->forceFill([
            'public_slug' => 'mentana', 'public_enabled' => true, 'label_prefix' => 'MEN',
        ])->save();
        $this->tipo = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $this->actingAsTenantUser($this->utente);
    }

    private function albero(array $campi): Tree
    {
        $albero = new Tree;
        $albero->forceFill($campi);

        return $albero;
    }

    /** @return array<string, array> le voci per chiave, come le legge una pagina */
    private function vociDi(Tree $albero): array
    {
        $stima = ServiziEcosistemici::per($albero);

        return $stima === null ? [] : collect($stima['voci'])->keyBy('chiave')->all();
    }

    public function test_senza_dati_non_si_stima_niente(): void
    {
        $this->assertNull(ServiziEcosistemici::per($this->albero(['genus' => 'Tilia'])));
        $this->assertNull(ServiziEcosistemici::per(null));
    }

    public function test_l_ossigeno_segue_l_assorbimento_annuo_di_anidride_carbonica(): void
    {
        $albero = $this->albero(['genus' => 'Tilia', 'dbh_cm' => 40, 'age_years_est' => 50]);

        $annuo = CarbonEstimate::per($albero)['annuo_kg'];
        $ossigeno = $this->vociDi($albero)['ossigeno'];

        // Rapporto fra le masse molecolari, non un coefficiente inventato
        $this->assertEqualsWithDelta(
            $annuo * config('benefici.ossigeno_per_co2'),
            $ossigeno['valore'],
            0.1,
        );
        $this->assertSame('kg/anno', $ossigeno['unita']);
    }

    public function test_senza_eta_non_c_e_ossigeno(): void
    {
        // L'ossigeno e' un flusso: senza eta' non c'e' assorbimento annuo da
        // cui ricavarlo, e la voce non compare
        $voci = $this->vociDi($this->albero(['genus' => 'Tilia', 'dbh_cm' => 40]));

        $this->assertArrayNotHasKey('ossigeno', $voci);
    }

    public function test_polveri_e_pioggia_si_calcolano_sull_area_di_chioma(): void
    {
        config([
            'benefici.pm10_g_per_m2_anno' => 6.0,
            'benefici.pm25_g_per_m2_anno' => 0.4,
            'benefici.pioggia_mm_anno' => 800,
            'benefici.frazione_intercettazione' => 0.20,
        ]);

        $albero = $this->albero(['genus' => 'Tilia', 'crown_diameter_m' => 6]);
        $stima = ServiziEcosistemici::per($albero);
        $voci = collect($stima['voci'])->keyBy('chiave');

        $areaAttesa = M_PI * 9; // raggio 3 metri
        $this->assertEqualsWithDelta($areaAttesa, $stima['chioma_m2'], 0.1);
        $this->assertEqualsWithDelta($areaAttesa * 6.0, $voci['pm10']['valore'], 0.1);
        $this->assertEqualsWithDelta($areaAttesa * 0.4, $voci['pm25']['valore'], 0.1);
        // Un millimetro di pioggia su un metro quadrato e' un litro
        $this->assertEqualsWithDelta($areaAttesa * 800 * 0.20, $voci['pioggia']['valore'], 1.0);
        $this->assertSame('litri/anno', $voci['pioggia']['unita']);
    }

    public function test_senza_chioma_niente_polveri_ne_pioggia(): void
    {
        $voci = $this->vociDi($this->albero(['genus' => 'Tilia', 'dbh_cm' => 40, 'age_years_est' => 50]));

        $this->assertArrayHasKey('ossigeno', $voci);
        $this->assertArrayNotHasKey('pm10', $voci);
        $this->assertArrayNotHasKey('pioggia', $voci);
    }

    public function test_una_chioma_fuori_scala_non_produce_stime(): void
    {
        // 90 metri di chioma sono un errore di digitazione: moltiplicato per
        // l'area diventerebbe un numero grottesco in prima pagina
        $voci = $this->vociDi($this->albero(['genus' => 'Tilia', 'crown_diameter_m' => 90]));

        $this->assertSame([], $voci);
    }

    public function test_gli_euro_non_compaiono_senza_prezzo_e_fonte(): void
    {
        config(['benefici.euro_per_kg_pm10' => 0, 'benefici.fonte_prezzo_polveri' => '']);
        $voci = $this->vociDi($this->albero(['genus' => 'Tilia', 'crown_diameter_m' => 6]));
        $this->assertNull($voci['pm10']['euro']);

        // Prezzo senza fonte dichiarata: gli euro restano spenti
        config(['benefici.euro_per_kg_pm10' => 50000, 'benefici.fonte_prezzo_polveri' => '']);
        $voci = $this->vociDi($this->albero(['genus' => 'Tilia', 'crown_diameter_m' => 6]));
        $this->assertNull($voci['pm10']['euro']);

        config(['benefici.fonte_prezzo_polveri' => 'fonte di prova']);
        $voci = $this->vociDi($this->albero(['genus' => 'Tilia', 'crown_diameter_m' => 6]));
        $this->assertNotNull($voci['pm10']['euro']);
        $this->assertSame(50000.0, $voci['pm10']['prezzo']);
        $this->assertSame('fonte di prova', $voci['pm10']['prezzo_fonte']);
    }

    public function test_il_totale_dice_su_quanti_alberi_e_calcolata_ogni_voce(): void
    {
        $totale = ServiziEcosistemici::totale([
            $this->albero(['genus' => 'Tilia', 'dbh_cm' => 40, 'age_years_est' => 50, 'crown_diameter_m' => 6]),
            $this->albero(['genus' => 'Tilia', 'dbh_cm' => 30, 'age_years_est' => 30]),
            $this->albero(['genus' => 'Tilia', 'crown_diameter_m' => 4]),
            $this->albero(['genus' => 'Tilia']),
        ]);

        $voci = collect($totale['voci'])->keyBy('chiave');
        $this->assertSame(2, $voci['ossigeno']['alberi'], 'Ossigeno: i due alberi con eta');
        $this->assertSame(2, $voci['pm10']['alberi'], 'Polveri: i due alberi con chioma');
        $this->assertNotEmpty($totale['metodo']);
    }

    public function test_su_un_patrimonio_i_litri_diventano_metri_cubi(): void
    {
        $tanti = array_fill(0, 20, null);
        $totale = ServiziEcosistemici::totale(array_map(
            fn () => $this->albero(['genus' => 'Tilia', 'crown_diameter_m' => 8]),
            $tanti,
        ));

        $pioggia = collect($totale['voci'])->firstWhere('chiave', 'pioggia');
        $this->assertSame('m³/anno', $pioggia['unita']);
        $this->assertGreaterThan(0, $pioggia['valore']);
    }

    public function test_in_pubblico_i_benefici_compaiono_solo_se_accesi(): void
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->tipo->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => ['genus' => 'Tilia', 'species' => 'Tilia cordata', 'dbh_cm' => 38,
                'age_years_est' => 45, 'crown_diameter_m' => 7],
        ])->assertOk();

        $this->get('/comune/mentana/elemento/MEN-0001')
            ->assertOk()
            ->assertDontSee('Ossigeno liberato');

        $this->patchJson("/api/v1/clients/{$this->committente->id}", [
            'public_profile' => ['show_co2' => true],
        ])->assertOk();

        $risposta = $this->get('/comune/mentana/elemento/MEN-0001')->assertOk();
        $risposta->assertSee('Ossigeno liberato');
        $risposta->assertSee('Polveri PM10 trattenute');
        $risposta->assertSee('Pioggia intercettata dalla chioma');
        // Il metodo va sempre dichiarato accanto ai valori
        $risposta->assertSee('Nowak');
    }

    public function test_la_home_del_portale_somma_i_benefici_del_patrimonio(): void
    {
        foreach ([['crown_diameter_m' => 6], ['crown_diameter_m' => 5], []] as $misure) {
            $id = $this->postJson('/api/v1/assets', [
                'area_id' => $this->area->id,
                'object_type_id' => $this->tipo->id,
                'geometry' => $this->pointGeometry(),
            ])->assertCreated()->json('data.id');

            $this->patchJson("/api/v1/assets/{$id}", [
                'tree' => ['genus' => 'Tilia', 'dbh_cm' => 30, ...$misure],
            ])->assertOk();
        }

        $this->patchJson("/api/v1/clients/{$this->committente->id}", [
            'public_profile' => ['show_co2' => true],
        ])->assertOk();

        $this->get('/comune/mentana')
            ->assertOk()
            ->assertSee('Polveri PM10 trattenute')
            // Tre alberi, ma la chioma la conoscono in due
            ->assertSee('su 2 alberi');
    }

    public function test_la_scheda_del_gestionale_riporta_le_stesse_voci(): void
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->tipo->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => ['genus' => 'Tilia', 'dbh_cm' => 38, 'age_years_est' => 45, 'crown_diameter_m' => 7],
        ])->assertOk();

        $scheda = $this->getJson("/api/v1/assets/{$id}")->assertOk()->json('data.benefici');

        $this->assertNotNull($scheda);
        $this->assertSame(
            ['ossigeno', 'pm10', 'pm25', 'pioggia'],
            array_column($scheda['voci'], 'chiave'),
        );
        $this->assertNotEmpty($scheda['metodo']);
    }
}
