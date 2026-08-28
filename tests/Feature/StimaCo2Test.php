<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Tree;
use App\Services\Benefits\CarbonEstimate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Stima dell'anidride carbonica: calcolo, prudenza e pubblicazione.
 *
 * Il valore non è mai automatico: si mostra solo se il committente lo ha
 * acceso, e solo dove i dati di campo lo permettono.
 */
class StimaCo2Test extends TestCase
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
        $this->client->forceFill([
            'public_slug' => 'mentana', 'public_enabled' => true, 'label_prefix' => 'MEN',
        ])->save();
        $this->type = $this->makeObjectType($this->organization, 'P', 'P103108');
        $this->actingAsTenantUser($this->user);
    }

    private function albero(array $campi): Tree
    {
        $albero = new Tree;
        $albero->forceFill($campi);

        return $albero;
    }

    public function test_senza_diametro_non_si_stima_niente(): void
    {
        $this->assertNull(CarbonEstimate::per($this->albero(['genus' => 'Tilia'])));
        $this->assertNull(CarbonEstimate::per(null));
    }

    public function test_la_circonferenza_basta_quando_manca_il_diametro(): void
    {
        $stima = CarbonEstimate::per($this->albero([
            'genus' => 'Tilia', 'trunk_circumference_cm' => 100,
        ]));

        $this->assertNotNull($stima);
        // 100 cm di circonferenza sono circa 31,8 cm di diametro
        $this->assertEqualsWithDelta(31.8, $stima['diametro_cm'], 0.2);
    }

    public function test_la_stima_cresce_con_il_diametro(): void
    {
        $piccolo = CarbonEstimate::per($this->albero(['genus' => 'Tilia', 'dbh_cm' => 10]));
        $grande = CarbonEstimate::per($this->albero(['genus' => 'Tilia', 'dbh_cm' => 40]));

        $this->assertGreaterThan($piccolo['co2_kg'], $grande['co2_kg']);
        // Un albero di 40 cm non può pesare quanto uno di 10
        $this->assertGreaterThan(10, $grande['co2_kg'] / $piccolo['co2_kg']);
    }

    public function test_conifere_e_latifoglie_usano_coefficienti_diversi(): void
    {
        $conifera = CarbonEstimate::per($this->albero(['genus' => 'Cupressus', 'dbh_cm' => 30]));
        $latifoglia = CarbonEstimate::per($this->albero(['genus' => 'Tilia', 'dbh_cm' => 30]));

        $this->assertSame('conifere', $conifera['gruppo']);
        $this->assertSame('latifoglie', $latifoglia['gruppo']);
        $this->assertNotEquals($conifera['co2_kg'], $latifoglia['co2_kg']);
    }

    public function test_il_genere_si_ricava_anche_dal_binomio(): void
    {
        $stima = CarbonEstimate::per($this->albero(['species' => 'Pinus pinea', 'dbh_cm' => 30]));

        $this->assertSame('conifere', $stima['gruppo']);
    }

    public function test_l_assorbimento_annuo_richiede_l_eta(): void
    {
        $senzaEta = CarbonEstimate::per($this->albero(['genus' => 'Tilia', 'dbh_cm' => 30]));
        $conEta = CarbonEstimate::per($this->albero(['genus' => 'Tilia', 'dbh_cm' => 30, 'age_years_est' => 40]));

        $this->assertNull($senzaEta['annuo_kg']);
        $this->assertEqualsWithDelta($conEta['co2_kg'] / 40, $conEta['annuo_kg'], 0.2);
    }

    public function test_oltre_il_limite_di_validita_la_stima_non_si_fa(): void
    {
        $this->assertNull(CarbonEstimate::per($this->albero(['genus' => 'Tilia', 'dbh_cm' => 400])));
    }

    public function test_in_pubblico_la_stima_compare_solo_se_accesa(): void
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => ['genus' => 'Tilia', 'species' => 'Tilia cordata', 'dbh_cm' => 38, 'age_years_est' => 45],
        ])->assertOk();

        // Spenta di partenza: il numero non esce
        $this->get('/comune/mentana/elemento/MEN-0001')
            ->assertOk()
            ->assertDontSee('Anidride carbonica immagazzinata');

        $this->patchJson("/api/v1/clients/{$this->client->id}", [
            'public_profile' => ['show_co2' => true],
        ])->assertOk();

        $risposta = $this->get('/comune/mentana/elemento/MEN-0001');
        $risposta->assertOk();
        $risposta->assertSee('Anidride carbonica immagazzinata');
        $risposta->assertSee('Assorbimento medio annuo');
        // Il metodo va sempre dichiarato accanto al valore
        $risposta->assertSee('Valore stimato, non misurato');
        $risposta->assertSee('Jenkins');
    }

    public function test_la_home_somma_solo_gli_alberi_con_diametro(): void
    {
        foreach ([['dbh_cm' => 30], ['dbh_cm' => 20], []] as $misure) {
            $id = $this->postJson('/api/v1/assets', [
                'area_id' => $this->area->id,
                'object_type_id' => $this->type->id,
                'geometry' => $this->pointGeometry(),
            ])->assertCreated()->json('data.id');

            $this->patchJson("/api/v1/assets/{$id}", [
                'tree' => ['genus' => 'Tilia', ...$misure],
            ])->assertOk();
        }

        $this->patchJson("/api/v1/clients/{$this->client->id}", [
            'public_profile' => ['show_co2' => true],
        ])->assertOk();

        $this->get('/comune/mentana')
            ->assertOk()
            ->assertSee('Anidride carbonica immagazzinata')
            // Tre alberi in tutto, ma la stima vale su due
            ->assertSee('su 2 alberi');
    }

    public function test_il_controvalore_viaggia_sempre_con_prezzo_e_fonte(): void
    {
        config(['co2.euro_per_tonnellata' => 80.0, 'co2.prezzo_fonte' => 'fonte di prova']);

        $stima = CarbonEstimate::per($this->albero([
            'genus' => 'Tilia', 'dbh_cm' => 38, 'age_years_est' => 40,
        ]));

        $this->assertEqualsWithDelta($stima['co2_kg'] / 1000 * 80, $stima['valore_euro'], 0.01);
        $this->assertSame(80.0, $stima['prezzo_tonnellata']);
        $this->assertSame('fonte di prova', $stima['prezzo_fonte']);
    }

    public function test_senza_fonte_dichiarata_gli_euro_non_si_pubblicano(): void
    {
        // Prezzo acceso ma fonte svuotata: un valore economico senza il suo
        // "come" non esce, né in pubblico né nel gestionale
        config(['co2.euro_per_tonnellata' => 70.0, 'co2.prezzo_fonte' => '  ']);

        $stima = CarbonEstimate::per($this->albero(['genus' => 'Tilia', 'dbh_cm' => 38]));

        $this->assertGreaterThan(0, $stima['co2_kg']);
        $this->assertNull($stima['valore_euro']);
        $this->assertNull($stima['prezzo_tonnellata']);
    }

    public function test_con_prezzo_spento_restano_solo_i_chilogrammi(): void
    {
        config(['co2.euro_per_tonnellata' => 0]);

        $stima = CarbonEstimate::per($this->albero(['genus' => 'Tilia', 'dbh_cm' => 38]));

        $this->assertGreaterThan(0, $stima['co2_kg']);
        $this->assertNull($stima['valore_euro']);
        $this->assertNull($stima['prezzo_tonnellata']);
        $this->assertNull($stima['prezzo_fonte']);
    }

    private function alberoPubblico(): void
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => ['genus' => 'Tilia', 'species' => 'Tilia cordata', 'dbh_cm' => 38, 'age_years_est' => 45],
        ])->assertOk();

        $this->patchJson("/api/v1/clients/{$this->client->id}", [
            'public_profile' => ['show_co2' => true],
        ])->assertOk();
    }

    public function test_in_pubblico_il_controvalore_dichiara_prezzo_e_fonte(): void
    {
        config(['co2.euro_per_tonnellata' => 70.0, 'co2.prezzo_fonte' => 'fonte di prova del prezzo']);
        $this->alberoPubblico();

        // La scheda dell'albero
        $this->get('/comune/mentana/elemento/MEN-0001')
            ->assertOk()
            ->assertSee('Controvalore economico stimato')
            ->assertSee('70 euro')
            ->assertSee('fonte di prova del prezzo');

        // La home col totale del patrimonio
        $this->get('/comune/mentana')
            ->assertOk()
            ->assertSee('Controvalore economico stimato')
            ->assertSee('fonte di prova del prezzo');
    }

    public function test_col_prezzo_spento_il_pubblico_non_vede_euro(): void
    {
        config(['co2.euro_per_tonnellata' => 0]);
        $this->alberoPubblico();

        $this->get('/comune/mentana/elemento/MEN-0001')
            ->assertOk()
            ->assertSee('Anidride carbonica immagazzinata')
            ->assertDontSee('Controvalore economico');

        $this->get('/comune/mentana')
            ->assertOk()
            ->assertSee('Anidride carbonica immagazzinata')
            ->assertDontSee('Controvalore economico');
    }

    public function test_la_home_dichiara_il_prezzo_con_cui_il_numero_e_stato_calcolato(): void
    {
        config(['co2.euro_per_tonnellata' => 80.0, 'co2.prezzo_fonte' => 'fonte iniziale']);
        $this->alberoPubblico();

        $this->get('/comune/mentana')->assertOk()
            ->assertSee('80 euro')->assertSee('fonte iniziale');

        // Il prezzo cambia, ma la statistica resta in cache 15 minuti: la
        // nota deve dichiarare il prezzo con cui il numero è stato davvero
        // calcolato, non quello nuovo che non c'entra col valore a video
        config(['co2.euro_per_tonnellata' => 95.0, 'co2.prezzo_fonte' => 'fonte nuova']);

        $this->get('/comune/mentana')->assertOk()
            ->assertSee('80 euro')->assertSee('fonte iniziale')
            ->assertDontSee('95 euro')->assertDontSee('fonte nuova');
    }

    public function test_un_prezzo_con_i_decimali_si_dichiara_con_i_decimali(): void
    {
        config(['co2.euro_per_tonnellata' => 66.5, 'co2.prezzo_fonte' => 'fonte di prova']);
        $this->alberoPubblico();

        // Il prezzo dichiarato deve essere esattamente quello applicato:
        // "67" mentre il conto usa 66,5 non sarebbe difendibile
        $this->get('/comune/mentana/elemento/MEN-0001')->assertOk()
            ->assertSee('66,50')->assertDontSee('67 euro');
    }

    public function test_la_scheda_interna_riporta_la_stessa_stima_del_portale(): void
    {
        config(['co2.euro_per_tonnellata' => 70.0]);

        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->type->id,
            'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        // Senza misure ancora nessuna stima, ma la chiave c'e' (nulla)
        $this->getJson("/api/v1/assets/{$id}")->assertOk()->assertJsonPath('data.co2', null);

        $this->patchJson("/api/v1/assets/{$id}", [
            'tree' => ['genus' => 'Tilia', 'dbh_cm' => 38, 'age_years_est' => 45],
        ])->assertOk();

        $risposta = $this->getJson("/api/v1/assets/{$id}")->assertOk();
        $this->assertGreaterThan(0, $risposta->json('data.co2.co2_kg'));
        $this->assertGreaterThan(0, $risposta->json('data.co2.valore_euro'));
        $this->assertNotEmpty($risposta->json('data.co2.prezzo_fonte'));
    }
}
