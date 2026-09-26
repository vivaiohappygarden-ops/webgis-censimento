<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Lavori nella veste nuova (blocco 4): l'elenco con i filtri "aperti" e "in
 * ritardo", la pagina dell'ordine e la sua cronologia (creazione, cambi di
 * stato dal registro, consuntivi, documenti collegati, conteggi per elemento).
 */
class LavoriNuoviTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    private $area;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.pages.paths' => [resource_path('js/Pages')]]);
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->area = $this->createArea($this->organizzazione);
        $this->actingAsTenantUser($this->utente);
    }

    private function ordine(array $attributi = []): WorkOrder
    {
        return WorkOrder::create([
            'tenant_id' => $this->organizzazione->id, 'code' => WorkOrder::nextCode($this->organizzazione->id),
            'title' => 'Lavoro di prova', 'status' => 'planned', 'area_id' => $this->area->id,
            'created_by' => $this->utente->id, 'updated_by' => $this->utente->id, ...$attributi,
        ]);
    }

    public function test_le_pagine_seguono_la_veste_e_i_permessi(): void
    {
        $ordine = $this->ordine();

        $this->get('/lavori')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Nuovo/Lavori'));
        $this->get('/lavori?precedente=1')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Lavori'));
        $this->get("/lavori/{$ordine->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('Nuovo/Ordine')->where('ordineId', $ordine->id));

        $this->utente->settings = ['interfaccia' => 'precedente'];
        $this->utente->save();
        $this->get('/lavori')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Lavori'));

        [, $cliente] = $this->createTenantUser([], 'cliente');
        $this->actingAsTenantUser($cliente);
        $this->get('/lavori')->assertForbidden();
        $this->get("/lavori/{$ordine->id}")->assertForbidden();
        $this->getJson("/api/v1/work-orders/{$ordine->id}/cronologia")->assertForbidden();
    }

    public function test_l_elenco_filtra_gli_aperti_e_quelli_in_ritardo(): void
    {
        $ritardo = $this->ordine(['title' => 'In ritardo', 'planned_start' => now()->subDays(6)->toDateString(), 'planned_end' => now()->subDay()->toDateString()]);
        $futuro = $this->ordine(['title' => 'Prossima settimana', 'planned_start' => now()->addDays(3)->toDateString(), 'planned_end' => now()->addDays(4)->toDateString()]);
        $this->ordine(['title' => 'Chiuso', 'status' => 'completed', 'planned_end' => now()->subDays(3)->toDateString()]);
        $this->ordine(['title' => 'Annullato', 'status' => 'cancelled', 'planned_end' => now()->subDays(3)->toDateString()]);

        $ids = fn (string $url) => collect($this->getJson($url)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$ritardo->id, $futuro->id])->sort()->values()->all(), $ids('/api/v1/work-orders?aperti=1'));
        $this->assertSame([$ritardo->id], $ids('/api/v1/work-orders?in_ritardo=1'));
        $this->assertCount(4, $this->getJson('/api/v1/work-orders')->json('data'));
    }

    public function test_la_cronologia_dell_ordine_mette_in_fila_creazione_stati_consuntivi_e_conteggi(): void
    {
        $tipo = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $asset = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id, 'object_type_id' => $tipo->id, 'geometry' => $this->pointGeometry(),
        ])->assertCreated()->json('data.id');

        // Creato e portato a "pianificato" dalle API: le due voci finiscono nel registro
        $ordine = $this->postJson('/api/v1/work-orders', ['title' => 'Potatura di prova', 'area_id' => $this->area->id])->assertCreated()->json('data');
        $this->postJson("/api/v1/work-orders/{$ordine['id']}/transition", ['status' => 'planned', 'version' => $ordine['version']])->assertOk();
        $this->postJson("/api/v1/work-orders/{$ordine['id']}/assets", ['asset_id' => $asset])->assertOk();

        // Un consuntivo dal campo su quell'elemento
        DB::table('work_logs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->organizzazione->id, 'work_order_id' => $ordine['id'],
            'asset_id' => $asset, 'operator_id' => $this->utente->id, 'started_at' => now()->addMinutes(5)->toIso8601String(),
            'ended_at' => now()->addMinutes(65)->toIso8601String(), 'man_hours' => 1.5, 'quantity' => 1, 'unit' => 'pianta',
            'notes' => 'Ramo secco rimosso', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $dati = $this->getJson("/api/v1/work-orders/{$ordine['id']}/cronologia")->assertOk()->json('data');
        $eventi = collect($dati['eventi']);

        $this->assertSame(['consuntivo', 'stato', 'creato'], $eventi->pluck('tipo')->all());
        $this->assertSame('Stato: pianificato', $eventi[1]['titolo']);
        $this->assertSame('planned', $eventi[1]['stato']);
        $this->assertStringContainsString($this->utente->name, $eventi[2]['dettaglio']);
        $this->assertStringContainsString('creato a mano', $eventi[2]['dettaglio']);
        $this->assertStringContainsString('1,5 ore', $eventi[0]['dettaglio']);
        $this->assertStringContainsString('Ramo secco rimosso', $eventi[0]['dettaglio']);

        $this->assertSame([], $dati['documenti']);
        $this->assertSame(1, $dati['per_elemento'][$asset]['fatti']);
        $this->assertSame(now('Europe/Rome')->toDateString(), $dati['per_elemento'][$asset]['ultimo']);

        // Il dettaglio porta la geometria degli elementi per la mappa dell'ordine
        $riga = $this->getJson("/api/v1/work-orders/{$ordine['id']}")->assertOk()->json('data.assets.0');
        $this->assertSame('Point', $riga['asset']['geom_geojson']['type']);
        $this->assertArrayHasKey('tree', $riga['asset']);
    }

    public function test_la_fine_prevista_superata_e_un_fatto_di_oggi(): void
    {
        $ordine = $this->ordine(['planned_start' => now()->subDays(6)->toDateString(), 'planned_end' => now()->subDays(2)->toDateString()]);

        $eventi = collect($this->getJson("/api/v1/work-orders/{$ordine->id}/cronologia")->json('data.eventi'));
        $this->assertSame('ritardo', $eventi->first()['tipo']);
        $this->assertSame(now('Europe/Rome')->toDateString(), $eventi->first()['data']);

        // Chiuso, il ritardo non e' piu' un fatto
        $ordine->update(['status' => 'completed']);
        $this->assertNull(collect($this->getJson("/api/v1/work-orders/{$ordine->id}/cronologia")->json('data.eventi'))->firstWhere('tipo', 'ritardo'));
    }
}
