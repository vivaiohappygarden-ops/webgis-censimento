<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Services\Demo\PatrimonioDimostrativo;
use App\Services\Portale\PortalStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Il patrimonio dimostrativo: verosimile dentro il Comune Demo, impossibile
 * fuori. La regola che conta e' la seconda.
 */
class DemoPatrimonioTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private Organization $demo;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->demo] = $this->createTenantUser();
        $this->demo->forceFill(['slug' => PatrimonioDimostrativo::SLUG_DEMO])->save();
        // il seed lascia un committente, una localita' e il tipo "albero":
        // qui si ricrea lo stesso punto di partenza
        $this->createArea($this->demo, ['code' => 'AREA-001']);
        $this->makeObjectType($this->demo, 'P', 'P103108');
    }

    public function test_fuori_dall_organizzazione_demo_si_rifiuta(): void
    {
        [$altra] = $this->createTenantUser();
        $this->createArea($altra);
        $this->makeObjectType($altra, 'P', 'P103108');

        $this->expectException(\DomainException::class);
        PatrimonioDimostrativo::per($altra);
    }

    public function test_la_prova_conta_senza_scrivere(): void
    {
        $prima = Asset::withoutGlobalScopes()->count();

        $esito = PatrimonioDimostrativo::per($this->demo)->genera(40, prova: true);

        $this->assertSame(40, $esito['alberi']);
        $this->assertTrue($esito['prova']);
        // due aree nuove: la prima esiste gia' dal seed
        $this->assertSame(2, $esito['aree']);
        $this->assertSame($prima, Asset::withoutGlobalScopes()->count());
    }

    public function test_genera_un_patrimonio_verosimile_e_poi_non_si_somma(): void
    {
        $generatore = PatrimonioDimostrativo::per($this->demo);

        $anteprima = $generatore->genera(120, prova: true);
        $esito = $generatore->genera(120);

        // anteprima ed esecuzione dicono la stessa cosa: e' lo stesso metodo
        $this->assertSame($anteprima['alberi'], $esito['alberi']);
        $this->assertSame($anteprima['valutazioni'], $esito['valutazioni']);
        $this->assertSame(120, Asset::withoutGlobalScopes()->where('tenant_id', $this->demo->id)
            ->where('census_code', 'like', 'ALB-%')->count());

        // i numeri del portale escono come su un patrimonio vero: tutti e
        // quattro gli stati, alberi curati e potati, diciotto varieta' al piu'
        $client = Client::withoutGlobalScopes()->where('tenant_id', $this->demo->id)->firstOrFail();
        $numeri = PortalStats::per($client, conCache: false);
        $this->assertSame(120, $numeri['alberi']);
        $this->assertSame(120, array_sum($numeri['stati']));
        foreach (['sano', 'cura', 'potare', 'verifica'] as $stato) {
            $this->assertGreaterThan(0, $numeri['stati'][$stato], "Nessun albero nello stato {$stato}");
        }
        $this->assertGreaterThan(0, $numeri['potati']);
        $this->assertGreaterThan($numeri['potati'], $numeri['curati']);
        $this->assertLessThanOrEqual(18, $numeri['varieta']);

        // ogni elemento dichiara di essere finto
        $this->assertSame(0, Asset::withoutGlobalScopes()->where('tenant_id', $this->demo->id)
            ->where('census_code', 'like', 'ALB-%')->whereNull('notes')->count());

        // un secondo lancio non si somma al primo
        $this->expectException(\DomainException::class);
        $generatore->genera(120);
    }

    public function test_il_comando_si_ferma_senza_organizzazione_demo(): void
    {
        $this->demo->forceFill(['slug' => 'non-demo'])->save();

        $this->artisan('demo:patrimonio', ['--si' => true])
            ->expectsOutputToContain('Non esiste l\'organizzazione "demo"')
            ->assertFailed();
    }

    public function test_il_comando_genera_quello_che_annuncia(): void
    {
        $this->artisan('demo:patrimonio', ['--alberi' => 30, '--prova' => true])
            ->expectsOutputToContain('Verrebbero creati 30 alberi')
            ->assertSuccessful();
        $this->assertSame(0, Asset::withoutGlobalScopes()->where('census_code', 'like', 'ALB-%')->count());

        $this->artisan('demo:patrimonio', ['--alberi' => 30, '--si' => true])
            ->expectsOutputToContain('Fatto: 30 alberi')
            ->assertSuccessful();
        $this->assertSame(30, Asset::withoutGlobalScopes()->where('census_code', 'like', 'ALB-%')->count());
    }
}
