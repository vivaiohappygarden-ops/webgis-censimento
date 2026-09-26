<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use App\Models\WorkOrderAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Patrimonio, elenco con anteprima (veste nuova, blocco 2): l'elenco porta
 * specie, ultima VTA e ultimo lavoro; i filtri nuovi (ricontrollo VTA, senza
 * specie) valgono per elenco, riepilogo ed esportazioni insieme; la
 * cronologia di un elemento mette in fila quello che gli e' successo.
 */
class PatrimonioTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organizzazione;

    private $utente;

    private $area;

    private $tipoAlbero;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['inertia.pages.paths' => [resource_path('js/Pages')]]);
        [$this->organizzazione, $this->utente] = $this->createTenantUser();
        $this->area = $this->createArea($this->organizzazione);
        $this->tipoAlbero = $this->makeObjectType($this->organizzazione, 'P', 'P103108');
        $this->actingAsTenantUser($this->utente);
    }

    private function albero(array $albero = [], array $extra = []): string
    {
        $id = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $this->tipoAlbero->id,
            'geometry' => $this->pointGeometry(),
            ...$extra,
        ])->assertCreated()->json('data.id');

        if ($albero !== []) {
            $this->patchJson("/api/v1/assets/{$id}", ['tree' => $albero])->assertOk();
        }

        return $id;
    }

    private function valuta(string $assetId, string $scadenza, string $sopralluogo = '2026-01-15'): array
    {
        return $this->postJson("/api/v1/assets/{$assetId}/assessments", [
            'assessment_type' => 'vta_visual', 'assessed_on' => $sopralluogo, 'failure_class' => 'C', 'next_check_due' => $scadenza,
        ])->assertCreated()->json('data');
    }

    private function lavoroCompletato(string $assetId, string $titolo, string $completatoIl): WorkOrder
    {
        $ordine = WorkOrder::create([
            'tenant_id' => $this->organizzazione->id,
            'code' => WorkOrder::nextCode($this->organizzazione->id),
            'title' => $titolo, 'status' => 'completed', 'area_id' => $this->area->id,
            'planned_start' => $completatoIl, 'planned_end' => $completatoIl, 'completed_at' => $completatoIl.' 10:00:00',
            'created_by' => $this->utente->id, 'updated_by' => $this->utente->id,
        ]);
        // completed_at lo scrive il passaggio di stato, non la creazione: qui si forza
        $ordine->forceFill(['completed_at' => $completatoIl.' 10:00:00'])->save();
        WorkOrderAsset::create(['tenant_id' => $this->organizzazione->id, 'work_order_id' => $ordine->id, 'asset_id' => $assetId]);

        return $ordine;
    }

    public function test_la_pagina_si_apre_a_chi_vede_il_censimento(): void
    {
        $this->get('/patrimonio')->assertOk()->assertInertia(fn (Assert $pagina) => $pagina->component('Nuovo/Patrimonio'));

        [, $cliente] = $this->createTenantUser([], 'cliente');
        $this->actingAsTenantUser($cliente);
        $this->get('/patrimonio')->assertForbidden();
        $this->getJson('/api/v1/assets/riepilogo')->assertForbidden();
    }

    public function test_l_elenco_con_dettagli_porta_specie_vta_e_ultimo_lavoro_e_i_filtri_valgono_anche_per_l_esportazione(): void
    {
        $ieri = now('Europe/Rome')->subDay()->toDateString();
        $a = $this->albero(['species' => 'Tilia cordata', 'common_name' => 'Tiglio']);
        $this->valuta($a, $ieri);
        $this->lavoroCompletato($a, 'Potatura di contenimento', '2026-08-14');
        // Un secondo albero senza specie, mai valutato; e una vecchia potatura
        // che non e' l'ultima
        $b = $this->albero();
        $this->lavoroCompletato($a, 'Potatura vecchia', '2025-03-01');

        $righe = collect($this->getJson('/api/v1/assets?dettagli=1&ordina=cartellino')->assertOk()->json('data'));
        $rigaA = $righe->firstWhere('id', $a);
        $rigaB = $righe->firstWhere('id', $b);

        $this->assertSame('Tilia cordata', $rigaA['tree']['species']);
        $this->assertSame('C', $rigaA['vta_classe']);
        $this->assertSame($ieri, substr((string) $rigaA['vta_scadenza'], 0, 10));
        $this->assertSame('Potatura di contenimento', $rigaA['ultimo_lavoro_titolo']);
        $this->assertSame('2026-08-14', substr((string) $rigaA['ultimo_lavoro_data'], 0, 10));
        $this->assertSame(0, (int) $rigaA['n_foto']);
        $this->assertNull($rigaB['vta_classe']);
        $this->assertNull($rigaB['ultimo_lavoro_titolo']);
        $this->assertNull($rigaB['tree']['species']);

        // Senza dettagli l'elenco resta quello di sempre
        $this->assertArrayNotHasKey('vta_classe', $this->getJson('/api/v1/assets')->json('data.0'));

        $ids = fn (string $url) => collect($this->getJson($url)->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$a], $ids('/api/v1/assets?vta=scaduta'));
        $this->assertSame([$b], $ids('/api/v1/assets?vta=mai'));
        $this->assertSame([$a], $ids('/api/v1/assets?vta=valutato'));
        $this->assertSame([], $ids('/api/v1/assets?vta=in_scadenza'));
        $this->assertSame([$b], $ids('/api/v1/assets?senza_specie=1'));
        $this->getJson('/api/v1/assets?vta=boh')->assertUnprocessable();

        $riepilogo = $this->getJson('/api/v1/assets/riepilogo')->assertOk()->json('data');
        $this->assertSame(['totale' => 2, 'alberi' => 2, 'vta_scadute' => 1, 'vta_mai' => 1, 'senza_specie' => 1], $riepilogo);
        $this->assertSame(1, $this->getJson('/api/v1/assets/riepilogo?vta=scaduta')->json('data.totale'));

        // L'esportazione usa gli stessi filtri: una sola riga oltre l'intestazione
        $csv = $this->get('/api/v1/exports/assets.csv?vta=scaduta')->assertOk()->streamedContent();
        $this->assertCount(2, array_filter(explode("\n", trim($csv))));
        $this->assertStringContainsString('Tilia cordata', $csv);
    }

    public function test_la_cronologia_mette_in_fila_rilievo_valutazione_lavoro_e_foto(): void
    {
        $a = $this->albero(['species' => 'Tilia cordata'], ['surveyed_at' => '2025-05-12', 'gps_accuracy_m' => 2.1]);
        $valutazione = $this->valuta($a, '2027-01-15', '2026-01-15');
        $ordine = $this->lavoroCompletato($a, 'Potatura di contenimento', '2026-08-14');
        $this->postJson("/api/v1/assets/{$a}/photos", ['photo' => UploadedFile::fake()->image('tiglio.jpg', 640, 480)])->assertCreated();

        $cronologia = $this->getJson("/api/v1/assets/{$a}/cronologia")->assertOk()->json('data');
        $eventi = collect($cronologia['eventi']);

        // Dal piu' recente: la foto di oggi, il lavoro, la valutazione, il rilievo
        $tipi = $eventi->pluck('tipo')->filter(fn ($t) => $t !== 'modifica')->values()->all();
        $this->assertSame(['foto', 'lavoro', 'valutazione', 'rilievo'], $tipi);
        $date = $eventi->pluck('data')->all();
        $this->assertSame($date, collect($date)->sortDesc()->values()->all(), 'La cronologia non e\' in ordine dal piu\' recente');

        $lavoro = $eventi->firstWhere('tipo', 'lavoro');
        $this->assertSame('Potatura di contenimento', $lavoro['titolo']);
        $this->assertStringContainsString($ordine->code, $lavoro['dettaglio']);
        $this->assertStringContainsString('completato', $lavoro['dettaglio']);
        $this->assertSame('/lavori/'.$ordine->id, $lavoro['href']);

        $vta = $eventi->firstWhere('tipo', 'valutazione');
        $this->assertSame('Valutazione VTA · classe C', $vta['titolo']);
        $this->assertStringContainsString('ricontrollo entro il 15/01/2027', $vta['dettaglio']);
        $this->assertSame($valutazione['id'], $vta['id']);

        $rilievo = $eventi->firstWhere('tipo', 'rilievo');
        $this->assertSame('2025-05-12', $rilievo['data']);
        $this->assertSame('Rilievo in campo', $rilievo['titolo']);
        $this->assertStringContainsString($this->utente->name, $rilievo['dettaglio']);
        $this->assertStringContainsString('GPS 2,1 m', $rilievo['dettaglio']);

        $foto = $eventi->firstWhere('tipo', 'foto');
        $this->assertSame('1 fotografia', $foto['titolo']);
        $this->assertCount(1, $foto['foto']);

        // La modifica della scheda (la specie) e' un evento a se'
        $this->assertNotNull($eventi->firstWhere('tipo', 'modifica'));

        // Un altro studio non la vede
        [, $altro] = $this->createTenantUser();
        $this->actingAsTenantUser($altro);
        $this->getJson("/api/v1/assets/{$a}/cronologia")->assertNotFound();
    }
}
