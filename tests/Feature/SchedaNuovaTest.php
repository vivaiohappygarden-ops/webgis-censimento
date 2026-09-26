<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use App\Models\WorkOrderAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * La scheda dell'elemento nella veste nuova (blocco 3): si apre al posto di
 * quella precedente, che resta raggiungibile con ?precedente=1 e per chi ha
 * scelto la veste di prima. La tabella "Lavori e segnalazioni" legge i campi
 * espliciti della cronologia.
 */
class SchedaNuovaTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    private $area;

    private string $asset;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.pages.paths' => [resource_path('js/Pages')]]);
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->area = $this->createArea($this->organizzazione);
        $tipo = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $this->actingAsTenantUser($this->utente);
        $this->asset = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id, 'object_type_id' => $tipo->id, 'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');
    }

    public function test_nella_veste_nuova_si_apre_la_scheda_nuova_e_quella_di_prima_resta_a_portata(): void
    {
        $this->get("/censimento/{$this->asset}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Nuovo/Scheda')->where('assetId', $this->asset)->has('navigazioneUrl'));

        $this->get("/censimento/{$this->asset}?precedente=1")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Censimento/Show')->where('assetId', $this->asset));

        $this->utente->settings = ['interfaccia' => 'precedente'];
        $this->utente->save();
        $this->get("/censimento/{$this->asset}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Censimento/Show'));
    }

    public function test_la_cronologia_porta_i_campi_della_tabella_lavori_e_segnalazioni(): void
    {
        $ordine = WorkOrder::create([
            'tenant_id' => $this->organizzazione->id, 'code' => WorkOrder::nextCode($this->organizzazione->id),
            'title' => 'Ricontrollo VTA', 'status' => 'planned', 'area_id' => $this->area->id, 'origin' => 'vta_recheck',
            'planned_start' => '2026-10-01', 'planned_end' => '2026-10-03',
            'created_by' => $this->utente->id, 'updated_by' => $this->utente->id,
        ]);
        WorkOrderAsset::create(['tenant_id' => $this->organizzazione->id, 'work_order_id' => $ordine->id, 'asset_id' => $this->asset]);

        $this->postJson('/api/v1/issues', [
            'description' => 'Ramo secco sul marciapiede', 'severity' => 'high', 'reporter_type' => 'internal',
            'asset_id' => $this->asset, 'area_id' => $this->area->id,
        ])->assertCreated();

        $eventi = collect($this->getJson("/api/v1/assets/{$this->asset}/cronologia")->assertOk()->json('data.eventi'));

        $lavoro = $eventi->firstWhere('tipo', 'lavoro');
        $this->assertSame($ordine->code, $lavoro['codice']);
        $this->assertSame('Pianificato', $lavoro['stato_etichetta']);
        $this->assertSame('01/10/2026 – 03/10/2026', $lavoro['periodo']);
        $this->assertSame('vta_recheck', $lavoro['origine']);
        $this->assertNull($lavoro['squadra']);

        $segnalazione = $eventi->firstWhere('tipo', 'segnalazione');
        $this->assertStringStartsWith('SEG-', $segnalazione['codice']);
        $this->assertSame('Aperta', $segnalazione['stato_etichetta']);
        $this->assertSame('alta', $segnalazione['gravita']);
    }
}
