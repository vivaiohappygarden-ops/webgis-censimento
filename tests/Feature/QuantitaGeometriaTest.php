<?php

namespace Tests\Feature;

use App\Models\WorkOrder;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * La quantità del lavoro proposta dalla geometria: aggancio agli ordini,
 * ricalcolo, creazione dalla selezione sulla mappa, previsto valorizzato
 * e misura ripresa nelle voci di preventivo.
 */
class QuantitaGeometriaTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    private $area;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser();
        $this->area = $this->createArea($this->organization);
        $this->actingAsTenantUser($this->user);
    }

    private function lineGeometry(): array
    {
        return ['type' => 'LineString', 'coordinates' => [[9.1890, 45.4640], [9.1930, 45.4640]]];
    }

    private function makeAsset(string $geo, array $geometry, string $code): array
    {
        $type = $this->makeObjectType($this->organization, $geo);

        return $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $type->id,
            'census_code' => $code,
            'geometry' => $geometry,
        ])->assertCreated()->json('data');
    }

    private function makeWorkType(string $unit, string $code): WorkType
    {
        return WorkType::create([
            'tenant_id' => $this->organization->id,
            'code' => $code, 'name' => "Lavorazione {$code}", 'unit' => $unit,
        ]);
    }

    public function test_attach_proposes_area_from_polygon_for_mq_work_type(): void
    {
        $asset = $this->makeAsset('S', $this->squarePolygon(), 'QG-PRATO');
        $sfalcio = $this->makeWorkType('mq', 'SFA');
        $woId = $this->postJson('/api/v1/work-orders', ['title' => 'Sfalcio', 'work_type_id' => $sfalcio->id])
            ->json('data.id');

        $row = $this->postJson("/api/v1/work-orders/{$woId}/assets", ['asset_id' => $asset['id']])
            ->assertOk()->json('data.assets.0');

        $this->assertSame('mq', $row['unit']);
        $this->assertEqualsWithDelta((float) $asset['computed_area_sqm'], (float) $row['planned_quantity'], 0.01);
        $this->assertGreaterThan(0, (float) $row['planned_quantity']);
    }

    public function test_attach_proposes_length_for_linear_row_work_type(): void
    {
        $asset = $this->makeAsset('L', $this->lineGeometry(), 'QG-SIEPE');
        $tosatura = $this->makeWorkType('m', 'TOS');
        $woId = $this->postJson('/api/v1/work-orders', ['title' => 'Tosatura'])->json('data.id');

        $row = $this->postJson("/api/v1/work-orders/{$woId}/assets", [
            'asset_id' => $asset['id'],
            'work_type_id' => $tosatura->id,
        ])->assertOk()->json('data.assets.0');

        $this->assertSame('m', $row['unit']);
        $this->assertEqualsWithDelta((float) $asset['computed_length_m'], (float) $row['planned_quantity'], 0.01);
    }

    public function test_attach_proposes_one_for_cadauno_and_nothing_for_hours(): void
    {
        $albero = $this->makeAsset('P', $this->pointGeometry(), 'QG-ALB');
        $potatura = $this->makeWorkType('cad', 'POT');
        $noleggio = $this->makeWorkType('h', 'NOL');
        $woId = $this->postJson('/api/v1/work-orders', ['title' => 'Potature'])->json('data.id');

        $conCad = $this->postJson("/api/v1/work-orders/{$woId}/assets", [
            'asset_id' => $albero['id'], 'work_type_id' => $potatura->id,
        ])->assertOk()->json('data.assets.0');
        $this->assertSame(1.0, (float) $conCad['planned_quantity']);
        $this->assertSame('cad', $conCad['unit']);

        // A ore la geometria non risponde: niente proposta
        $senza = $this->postJson("/api/v1/work-orders/{$woId}/assets", [
            'asset_id' => $albero['id'], 'work_type_id' => $noleggio->id,
        ])->assertOk()->json('data.assets');
        $rigaOre = collect($senza)->firstWhere('work_type_id', $noleggio->id);
        $this->assertNull($rigaOre['planned_quantity']);
    }

    public function test_explicit_quantity_is_not_overridden(): void
    {
        $asset = $this->makeAsset('S', $this->squarePolygon(), 'QG-MANO');
        $sfalcio = $this->makeWorkType('mq', 'SFB');
        $woId = $this->postJson('/api/v1/work-orders', ['title' => 'A mano', 'work_type_id' => $sfalcio->id])
            ->json('data.id');

        $row = $this->postJson("/api/v1/work-orders/{$woId}/assets", [
            'asset_id' => $asset['id'],
            'planned_quantity' => 120.5,
            'unit' => 'mq',
        ])->assertOk()->json('data.assets.0');

        $this->assertEqualsWithDelta(120.5, (float) $row['planned_quantity'], 0.001);
    }

    public function test_row_quantity_can_be_edited_and_recomputed_from_geometry(): void
    {
        $asset = $this->makeAsset('S', $this->squarePolygon(), 'QG-RIC');
        $sfalcio = $this->makeWorkType('mq', 'SFC');
        $woId = $this->postJson('/api/v1/work-orders', ['title' => 'Ricalcolo', 'work_type_id' => $sfalcio->id])
            ->json('data.id');
        $row = $this->postJson("/api/v1/work-orders/{$woId}/assets", ['asset_id' => $asset['id']])
            ->json('data.assets.0');

        // Correzione a mano (la superficie lorda non è quella lavorata)
        $corretta = $this->patchJson("/api/v1/work-orders/{$woId}/assets/{$row['id']}", [
            'planned_quantity' => 500, 'unit' => 'mq',
        ])->assertOk()->json('data.assets.0');
        $this->assertEqualsWithDelta(500.0, (float) $corretta['planned_quantity'], 0.001);

        // "Riprendi dalla mappa": si torna alla misura geometrica di oggi
        $ricalcolata = $this->patchJson("/api/v1/work-orders/{$woId}/assets/{$row['id']}", ['ricalcola' => true])
            ->assertOk()->json('data.assets.0');
        $this->assertEqualsWithDelta((float) $asset['computed_area_sqm'], (float) $ricalcolata['planned_quantity'], 0.01);

        // La riga espone anche la misura geometrica corrente per il confronto
        $this->assertEqualsWithDelta((float) $asset['computed_area_sqm'], (float) $ricalcolata['quantita_geometrica'], 0.01);
    }

    public function test_recompute_fails_when_unit_is_not_geometric(): void
    {
        $albero = $this->makeAsset('P', $this->pointGeometry(), 'QG-ORE');
        $noleggio = $this->makeWorkType('h', 'NOM');
        $woId = $this->postJson('/api/v1/work-orders', ['title' => 'Ore', 'work_type_id' => $noleggio->id])
            ->json('data.id');
        $row = $this->postJson("/api/v1/work-orders/{$woId}/assets", ['asset_id' => $albero['id']])
            ->json('data.assets.0');

        $this->patchJson("/api/v1/work-orders/{$woId}/assets/{$row['id']}", ['ricalcola' => true])
            ->assertUnprocessable();
    }

    public function test_store_with_asset_ids_attaches_with_quantities_and_inherits_area(): void
    {
        $prato = $this->makeAsset('S', $this->squarePolygon(), 'QG-SEL1');
        $albero = $this->makeAsset('P', $this->pointGeometry(), 'QG-SEL2');
        $sfalcio = $this->makeWorkType('mq', 'SFD');

        $detail = $this->postJson('/api/v1/work-orders', [
            'title' => 'Dalla mappa',
            'work_type_id' => $sfalcio->id,
            'asset_ids' => [$prato['id'], $albero['id']],
        ])->assertCreated()->json('data');

        $this->assertCount(2, $detail['assets']);
        // Entrambi nella stessa area: l'ordine la eredita
        $this->assertSame($this->area->id, $detail['area_id']);

        $righe = collect($detail['assets'])->keyBy(fn ($r) => $r['asset']['census_code']);
        $this->assertEqualsWithDelta((float) $prato['computed_area_sqm'], (float) $righe['QG-SEL1']['planned_quantity'], 0.01);
        // Il punto non ha superficie: niente quantità in mq
        $this->assertNull($righe['QG-SEL2']['planned_quantity']);

        // Il totale previsto somma per unità
        $this->assertEqualsWithDelta((float) $prato['computed_area_sqm'], (float) $detail['previsto']['quantities']['mq'], 0.01);
    }

    public function test_store_rejects_unknown_asset_ids(): void
    {
        $this->postJson('/api/v1/work-orders', [
            'title' => 'Con estranei',
            'asset_ids' => ['00000000-0000-0000-0000-000000000001'],
        ])->assertUnprocessable();
    }

    public function test_previsto_is_valued_with_price_list(): void
    {
        $prato = $this->makeAsset('S', $this->squarePolygon(), 'QG-VAL');
        $sfalcio = $this->makeWorkType('mq', 'SFE');
        $listId = $this->postJson('/api/v1/price-lists', ['code' => 'LIS-QG', 'name' => 'Listino QG'])
            ->json('data.id');
        $this->putJson("/api/v1/price-lists/{$listId}/items", [
            'items' => [['work_type_id' => $sfalcio->id, 'unit' => 'mq', 'unit_price' => 0.5]],
        ])->assertOk();

        $detail = $this->postJson('/api/v1/work-orders', [
            'title' => 'Valorizzato',
            'work_type_id' => $sfalcio->id,
            'price_list_id' => $listId,
            'asset_ids' => [$prato['id']],
        ])->assertCreated()->json('data');

        $atteso = round(round((float) $prato['computed_area_sqm'], 2) * 0.5, 2);
        $this->assertEqualsWithDelta($atteso, (float) $detail['previsto']['valued']['amount'], 0.01);
        $this->assertSame('mq', $detail['previsto']['valued']['unit']);
    }

    public function test_asset_quantita_endpoint_answers_per_unit(): void
    {
        $prato = $this->makeAsset('S', $this->squarePolygon(), 'QG-END');

        $mq = $this->getJson("/api/v1/assets/{$prato['id']}/quantita?unit=mq")->assertOk()->json('data');
        $this->assertEqualsWithDelta((float) $prato['computed_area_sqm'], (float) $mq['quantity'], 0.01);
        $this->assertSame('superficie', $mq['tipo_misura']);

        // Un'unità non geometrica risponde con niente, non con un errore
        $ore = $this->getJson("/api/v1/assets/{$prato['id']}/quantita?unit=h")->assertOk()->json('data');
        $this->assertNull($ore['quantity']);
        $this->assertNull($ore['tipo_misura']);
    }

    public function test_estimate_item_can_reference_an_asset(): void
    {
        $prato = $this->makeAsset('S', $this->squarePolygon(), 'QG-PREV');
        $client = \App\Models\Client::create([
            'tenant_id' => $this->organization->id, 'name' => 'Comune QG', 'client_type' => 'public',
        ]);
        $estimate = $this->postJson('/api/v1/estimates', [
            'client_id' => $client->id, 'title' => 'Sfalci 2026',
        ])->assertCreated()->json('data');

        $salvato = $this->putJson("/api/v1/estimates/{$estimate['id']}/items", [
            'version' => $estimate['version'],
            'items' => [[
                'work_type_id' => null,
                'asset_id' => $prato['id'],
                'description' => 'Sfalcio prato QG-PREV',
                'unit' => 'mq',
                'quantity' => round((float) $prato['computed_area_sqm'], 2),
                'unit_price' => 0.4,
            ]],
        ])->assertOk()->json('data');

        $this->assertSame($prato['id'], $salvato['items'][0]['asset_id']);
        $this->assertSame('QG-PREV', $salvato['items'][0]['asset']['census_code']);
    }

    public function test_bulk_attach_proposes_quantities_too(): void
    {
        $prato = $this->makeAsset('S', $this->squarePolygon(), 'QG-BLK1');
        $albero = $this->makeAsset('P', $this->pointGeometry(), 'QG-BLK2');
        $sfalcio = $this->makeWorkType('mq', 'SFG');
        $woId = $this->postJson('/api/v1/work-orders', ['title' => 'In blocco', 'work_type_id' => $sfalcio->id])
            ->json('data.id');

        // L'azione multipla "Collega a lavoro" deve proporre come i percorsi uno-a-uno
        $this->postJson("/api/v1/azioni/lavori/{$woId}/collega-elementi", [
            'ids' => [$prato['id'], $albero['id']],
        ])->assertOk();

        $detail = $this->getJson("/api/v1/work-orders/{$woId}")->json('data');
        $righe = collect($detail['assets'])->keyBy(fn ($r) => $r['asset']['census_code']);
        $this->assertEqualsWithDelta((float) $prato['computed_area_sqm'], (float) $righe['QG-BLK1']['planned_quantity'], 0.01);
        $this->assertSame('mq', $righe['QG-BLK1']['unit']);
        // Il punto non ha superficie: resta senza quantità, come dall'aggancio singolo
        $this->assertNull($righe['QG-BLK2']['planned_quantity']);
    }

    public function test_a_corpo_is_not_multiplied_per_element(): void
    {
        $ceppaia = $this->makeAsset('P', $this->pointGeometry(), 'QG-CORPO');
        $forfait = $this->makeWorkType('a corpo', 'FRF');
        $woId = $this->postJson('/api/v1/work-orders', ['title' => 'Forfait', 'work_type_id' => $forfait->id])
            ->json('data.id');

        // Un forfait per l'intervento non è un prezzo per pezzo: nessuna proposta
        $row = $this->postJson("/api/v1/work-orders/{$woId}/assets", ['asset_id' => $ceppaia['id']])
            ->assertOk()->json('data.assets.0');
        $this->assertNull($row['planned_quantity']);
    }

    public function test_row_update_checks_order_version_and_decimals(): void
    {
        $prato = $this->makeAsset('S', $this->squarePolygon(), 'QG-VER');
        $sfalcio = $this->makeWorkType('mq', 'SFH');
        $detail = $this->postJson('/api/v1/work-orders', [
            'title' => 'Concorrenza righe', 'work_type_id' => $sfalcio->id, 'asset_ids' => [$prato['id']],
        ])->json('data');
        $rowId = $detail['assets'][0]['id'];

        // Più di 2 decimali: il DB arrotonderebbe in silenzio, meglio rifiutare
        $this->patchJson("/api/v1/work-orders/{$detail['id']}/assets/{$rowId}", [
            'planned_quantity' => 12.345, 'version' => $detail['version'],
        ])->assertUnprocessable();

        // Salvataggio valido: la versione dell'ordine avanza
        $dopo = $this->patchJson("/api/v1/work-orders/{$detail['id']}/assets/{$rowId}", [
            'planned_quantity' => 800, 'version' => $detail['version'],
        ])->assertOk()->json('data');
        $this->assertSame($detail['version'] + 1, $dopo['version']);

        // Chi ha ancora la versione vecchia riceve un conflitto, non sovrascrive
        $this->patchJson("/api/v1/work-orders/{$detail['id']}/assets/{$rowId}", [
            'planned_quantity' => 950, 'version' => $detail['version'],
        ])->assertConflict();
    }

    public function test_asset_referenced_by_estimate_cannot_be_deleted(): void
    {
        $prato = $this->makeAsset('S', $this->squarePolygon(), 'QG-DEL');
        $client = \App\Models\Client::create([
            'tenant_id' => $this->organization->id, 'name' => 'Comune QG3', 'client_type' => 'public',
        ]);
        $estimate = $this->postJson('/api/v1/estimates', [
            'client_id' => $client->id, 'title' => 'Blocca eliminazione',
        ])->json('data');
        $this->putJson("/api/v1/estimates/{$estimate['id']}/items", [
            'version' => $estimate['version'],
            'items' => [[
                'asset_id' => $prato['id'], 'description' => 'Sfalcio QG-DEL',
                'unit' => 'mq', 'quantity' => 100, 'unit_price' => 0.4,
            ]],
        ])->assertOk();

        // La voce di preventivo blocca l'eliminazione, come gli ordini di lavoro
        $risposta = $this->deleteJson("/api/v1/assets/{$prato['id']}")->assertUnprocessable();
        $this->assertStringContainsString('voce di preventivo', $risposta->json('errors.asset.0'));
    }

    public function test_estimate_item_rejects_unknown_asset(): void
    {
        $client = \App\Models\Client::create([
            'tenant_id' => $this->organization->id, 'name' => 'Comune QG2', 'client_type' => 'public',
        ]);
        $estimate = $this->postJson('/api/v1/estimates', [
            'client_id' => $client->id, 'title' => 'Con estraneo',
        ])->json('data');

        $this->putJson("/api/v1/estimates/{$estimate['id']}/items", [
            'version' => $estimate['version'],
            'items' => [[
                'asset_id' => '00000000-0000-0000-0000-000000000001',
                'description' => 'Voce', 'unit' => 'mq', 'quantity' => 1, 'unit_price' => 1,
            ]],
        ])->assertUnprocessable();
    }
}
