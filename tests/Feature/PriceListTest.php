<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\WorkOrder;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class PriceListTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    private $workType;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser();
        $this->workType = WorkType::create([
            'tenant_id' => $this->organization->id,
            'code' => 'SFA', 'name' => 'Sfalcio', 'unit' => 'mq',
        ]);
        $this->actingAsTenantUser($this->user);
    }

    public function test_price_list_crud_with_items(): void
    {
        $id = $this->postJson('/api/v1/price-lists', [
            'code' => 'LIS-2026', 'name' => 'Listino interno 2026', 'year' => 2026,
        ])->assertCreated()->json('data.id');

        // Codice doppio rifiutato
        $this->postJson('/api/v1/price-lists', ['code' => 'LIS-2026', 'name' => 'Doppione'])
            ->assertUnprocessable();

        $detail = $this->putJson("/api/v1/price-lists/{$id}/items", [
            'items' => [[
                'work_type_id' => $this->workType->id,
                'unit' => 'mq',
                'unit_price' => 0.35,
                'description' => 'Sfalcio con raccolta',
            ]],
        ])->assertOk()->json('data');

        $this->assertCount(1, $detail['items']);
        $this->assertSame('SFA', $detail['items'][0]['work_type']['code']);

        // Sostituzione integrale: la voce cambia prezzo, non si duplica
        $detail = $this->putJson("/api/v1/price-lists/{$id}/items", [
            'items' => [[
                'work_type_id' => $this->workType->id,
                'unit' => 'mq',
                'unit_price' => 0.42,
            ]],
        ])->assertOk()->json('data');

        $this->assertCount(1, $detail['items']);
        $this->assertEquals(0.42, (float) $detail['items'][0]['unit_price']);

        // Doppioni nel payload rifiutati prima del vincolo del DB
        $this->putJson("/api/v1/price-lists/{$id}/items", [
            'items' => [
                ['work_type_id' => $this->workType->id, 'unit' => 'mq', 'unit_price' => 1],
                ['work_type_id' => $this->workType->id, 'unit' => 'mq', 'unit_price' => 2],
            ],
        ])->assertUnprocessable();

        $this->deleteJson("/api/v1/price-lists/{$id}")->assertNoContent();
        $this->getJson("/api/v1/price-lists/{$id}")->assertNotFound();
    }

    public function test_price_list_in_use_cannot_be_deleted(): void
    {
        $id = $this->postJson('/api/v1/price-lists', ['code' => 'LIS-USO', 'name' => 'In uso'])
            ->json('data.id');
        WorkOrder::create([
            'tenant_id' => $this->organization->id,
            'code' => 'ODL-2026-9001', 'title' => 'Con listino', 'status' => 'draft',
            'price_list_id' => $id,
        ]);

        $this->deleteJson("/api/v1/price-lists/{$id}")->assertUnprocessable();
    }

    public function test_price_lists_are_tenant_isolated_and_operator_read_only(): void
    {
        $id = $this->postJson('/api/v1/price-lists', ['code' => 'LIS-A', 'name' => 'Riservato'])
            ->json('data.id');

        [, $otherUser] = $this->createTenantUser();
        $this->actingAsTenantUser($otherUser);
        $this->getJson('/api/v1/price-lists')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/price-lists/{$id}")->assertNotFound();

        [, $operator] = [null, \App\Models\User::factory()->create(['tenant_id' => $this->organization->id])];
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $operator->assignRole('operatore');
        $this->actingAsTenantUser($operator);
        $this->getJson('/api/v1/price-lists')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/price-lists', ['code' => 'LIS-B', 'name' => 'Vietato'])->assertForbidden();
    }

    public function test_multiple_price_bands_make_valuation_explicitly_ambiguous(): void
    {
        $listId = $this->postJson('/api/v1/price-lists', ['code' => 'LIS-F', 'name' => 'Fasce'])
            ->json('data.id');
        $this->putJson("/api/v1/price-lists/{$listId}/items", [
            'items' => [
                ['work_type_id' => $this->workType->id, 'item_code' => 'A', 'unit' => 'mq', 'unit_price' => 10],
                ['work_type_id' => $this->workType->id, 'item_code' => 'B', 'unit' => 'mq', 'unit_price' => 100],
            ],
        ])->assertOk();

        $woId = $this->postJson('/api/v1/work-orders', [
            'title' => 'Fasce multiple',
            'work_type_id' => $this->workType->id,
            'price_list_id' => $listId,
        ])->assertCreated()->json('data.id');
        \App\Models\WorkLog::create([
            'tenant_id' => $this->organization->id, 'work_order_id' => $woId,
            'operator_id' => $this->user->id, 'started_at' => now(),
            'quantity' => 100, 'unit' => 'mq',
        ]);

        // Mai scegliere una fascia in silenzio: l'ambiguità è dichiarata
        $valued = $this->getJson("/api/v1/work-orders/{$woId}")->json('data.consuntivo.valued');
        $this->assertTrue($valued['ambiguous']);
        $this->assertArrayNotHasKey('amount', $valued);
    }

    public function test_overhead_and_safety_cost_enter_the_valued_amount(): void
    {
        $listId = $this->postJson('/api/v1/price-lists', ['code' => 'LIS-O', 'name' => 'Oneri'])
            ->json('data.id');
        $this->putJson("/api/v1/price-lists/{$listId}/items", [
            'items' => [[
                'work_type_id' => $this->workType->id, 'unit' => 'mq',
                'unit_price' => 1, 'overhead_pct' => 10, 'safety_cost' => 20,
            ]],
        ])->assertOk();

        $woId = $this->postJson('/api/v1/work-orders', [
            'title' => 'Con oneri',
            'work_type_id' => $this->workType->id,
            'price_list_id' => $listId,
        ])->assertCreated()->json('data.id');
        \App\Models\WorkLog::create([
            'tenant_id' => $this->organization->id, 'work_order_id' => $woId,
            'operator_id' => $this->user->id, 'started_at' => now(),
            'quantity' => 100, 'unit' => 'mq',
        ]);

        $valued = $this->getJson("/api/v1/work-orders/{$woId}")->json('data.consuntivo.valued');
        $this->assertEquals(100.0, $valued['base_amount']);
        // 100 x 1 € + 10% spese generali + 20 € sicurezza
        $this->assertEquals(130.0, $valued['amount']);
    }

    public function test_items_of_a_list_used_by_closed_orders_cannot_be_rewritten(): void
    {
        $listId = $this->postJson('/api/v1/price-lists', ['code' => 'LIS-S', 'name' => 'Storico'])
            ->json('data.id');
        $this->putJson("/api/v1/price-lists/{$listId}/items", [
            'items' => [['work_type_id' => $this->workType->id, 'unit' => 'mq', 'unit_price' => 0.5]],
        ])->assertOk();

        $order = WorkOrder::create([
            'tenant_id' => $this->organization->id,
            'code' => 'ODL-2026-9002', 'title' => 'Da chiudere', 'status' => 'draft',
            'price_list_id' => $listId,
        ]);

        // Con l'ordine ancora vivo i prezzi si possono correggere
        $this->putJson("/api/v1/price-lists/{$listId}/items", [
            'items' => [['work_type_id' => $this->workType->id, 'unit' => 'mq', 'unit_price' => 0.6]],
        ])->assertOk();

        // Con un ordine chiuso la storia economica è congelata
        $order->update(['status' => 'cancelled']);
        $this->putJson("/api/v1/price-lists/{$listId}/items", [
            'items' => [['work_type_id' => $this->workType->id, 'unit' => 'mq', 'unit_price' => 9]],
        ])->assertUnprocessable();
    }

    public function test_deactivated_list_cannot_be_applied_to_orders(): void
    {
        $listId = $this->postJson('/api/v1/price-lists', ['code' => 'LIS-D', 'name' => 'Spento'])
            ->json('data.id');
        $this->patchJson("/api/v1/price-lists/{$listId}", ['is_active' => false])->assertOk();

        $this->postJson('/api/v1/work-orders', [
            'title' => 'Con listino spento',
            'price_list_id' => $listId,
        ])->assertUnprocessable();
    }

    public function test_work_order_consuntivo_is_valued_from_price_list(): void
    {
        $listId = $this->postJson('/api/v1/price-lists', ['code' => 'LIS-V', 'name' => 'Valori'])
            ->json('data.id');
        $this->putJson("/api/v1/price-lists/{$listId}/items", [
            'items' => [[
                'work_type_id' => $this->workType->id, 'unit' => 'mq', 'unit_price' => 0.5,
            ]],
        ])->assertOk();

        $woId = $this->postJson('/api/v1/work-orders', [
            'title' => 'Sfalcio valorizzato',
            'work_type_id' => $this->workType->id,
            'price_list_id' => $listId,
        ])->assertCreated()->json('data.id');

        // Due consuntivi in mq e uno in un'unità diversa (non valorizzabile)
        foreach ([
            ['quantity' => 1200, 'unit' => 'mq', 'man_hours' => 6],
            ['quantity' => 300, 'unit' => 'mq', 'man_hours' => 1.5],
            ['quantity' => 4, 'unit' => 'cad', 'man_hours' => 2],
        ] as $log) {
            \App\Models\WorkLog::create([
                'tenant_id' => $this->organization->id,
                'work_order_id' => $woId,
                'operator_id' => $this->user->id,
                'started_at' => now(),
                ...$log,
            ]);
        }

        $consuntivo = $this->getJson("/api/v1/work-orders/{$woId}")
            ->assertOk()->json('data.consuntivo');

        $this->assertEquals(9.5, $consuntivo['total_man_hours']);
        $this->assertEquals(1500, $consuntivo['quantities']['mq']);
        $this->assertEquals(4, $consuntivo['quantities']['cad']);
        $this->assertEquals('mq', $consuntivo['valued']['unit']);
        $this->assertEquals(0.5, $consuntivo['valued']['unit_price']);
        $this->assertEquals(750.0, $consuntivo['valued']['amount']);
    }
}
