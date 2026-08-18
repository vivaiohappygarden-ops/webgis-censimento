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
}
