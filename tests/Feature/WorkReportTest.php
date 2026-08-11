<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Team;
use App\Models\WorkLog;
use App\Models\WorkOrder;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class WorkReportTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    private $workType;

    private $listId;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser();
        $this->actingAsTenantUser($this->user);

        $this->workType = WorkType::create([
            'tenant_id' => $this->organization->id,
            'code' => 'SFA', 'name' => 'Sfalcio', 'unit' => 'mq',
        ]);
        $this->listId = $this->postJson('/api/v1/price-lists', ['code' => 'LIS-R', 'name' => 'Rendiconto'])
            ->json('data.id');
        $this->putJson("/api/v1/price-lists/{$this->listId}/items", [
            'items' => [['work_type_id' => $this->workType->id, 'unit' => 'mq', 'unit_price' => 0.5]],
        ])->assertOk();
    }

    private function makeCompleted(array $attributes, array $logs = [], string $completedAt = '2026-08-05 10:00:00'): WorkOrder
    {
        $order = WorkOrder::create([
            'tenant_id' => $this->organization->id,
            'code' => 'ODL-2026-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'Rendicontabile',
            'status' => 'completed',
            ...$attributes,
        ]);
        WorkOrder::query()->whereKey($order->id)->update(['completed_at' => $completedAt]);
        foreach ($logs as $log) {
            WorkLog::create([
                'tenant_id' => $this->organization->id,
                'work_order_id' => $order->id,
                'operator_id' => $this->user->id,
                'started_at' => now(),
                ...$log,
            ]);
        }

        return $order->fresh();
    }

    public function test_transition_to_completed_stamps_completed_at(): void
    {
        $team = Team::create(['tenant_id' => $this->organization->id, 'name' => 'Squadra R']);
        $id = $this->postJson('/api/v1/work-orders', ['title' => 'Da chiudere', 'team_id' => $team->id])
            ->json('data.id');
        foreach (['planned', 'assigned', 'in_progress', 'completed'] as $status) {
            $this->postJson("/api/v1/work-orders/{$id}/transition", ['status' => $status])->assertOk();
        }

        $this->assertNotNull(WorkOrder::query()->findOrFail($id)->completed_at);
    }

    public function test_report_groups_by_client_with_totals_and_value_states(): void
    {
        $clientA = Client::create(['tenant_id' => $this->organization->id, 'name' => 'Comune A', 'client_type' => 'public']);
        $clientB = Client::create(['tenant_id' => $this->organization->id, 'name' => 'Privato B', 'client_type' => 'private']);

        // Cliente A: valorizzato (1000 mq x 0,50 = 500) con 5 ore
        $this->makeCompleted(
            ['client_id' => $clientA->id, 'work_type_id' => $this->workType->id, 'price_list_id' => $this->listId],
            [['quantity' => 1000, 'unit' => 'mq', 'man_hours' => 5]],
        );
        // Cliente B: senza listino (3 ore, non valorizzato)
        $this->makeCompleted(
            ['client_id' => $clientB->id, 'work_type_id' => $this->workType->id],
            [['man_hours' => 3]],
        );
        // Fuori finestra: non deve comparire
        $this->makeCompleted(['client_id' => $clientA->id], completedAt: '2026-07-01 10:00:00');
        // Non completato: non deve comparire
        WorkOrder::create([
            'tenant_id' => $this->organization->id, 'code' => 'ODL-2026-0999',
            'title' => 'In corso', 'status' => 'in_progress',
            'team_id' => Team::create(['tenant_id' => $this->organization->id, 'name' => 'S'])->id,
        ]);

        $report = $this->getJson('/api/v1/reports/lavori?from=2026-08-01&to=2026-08-31')
            ->assertOk()->json();

        $this->assertSame(2, $report['orders_count']);
        $this->assertEquals(500.0, $report['total_amount']);
        $this->assertEquals(8.0, $report['total_hours']);
        $this->assertSame(1, $report['unvalued_count']);

        $groupA = collect($report['clients'])->firstWhere('client.name', 'Comune A');
        $this->assertEquals(500.0, $groupA['subtotal_amount']);
        $this->assertSame('ok', $groupA['orders'][0]['value_state']);
        $groupB = collect($report['clients'])->firstWhere('client.name', 'Privato B');
        $this->assertSame('no_list', $groupB['orders'][0]['value_state']);

        // Filtro cliente
        $this->getJson('/api/v1/reports/lavori?from=2026-08-01&to=2026-08-31&client_id='.$clientA->id)
            ->assertOk()->assertJsonPath('orders_count', 1);
    }

    public function test_period_boundaries_follow_italian_days_not_utc(): void
    {
        $client = Client::create(['tenant_id' => $this->organization->id, 'name' => 'Notturno', 'client_type' => 'private']);
        // 22:30 UTC del 31/08 = 00:30 del 1/09 in Italia: appartiene a settembre
        $order = $this->makeCompleted(['client_id' => $client->id], completedAt: '2026-08-31 22:30:00');

        $this->getJson('/api/v1/reports/lavori?from=2026-08-01&to=2026-08-31')
            ->assertOk()->assertJsonPath('orders_count', 0);

        $september = $this->getJson('/api/v1/reports/lavori?from=2026-09-01&to=2026-09-30')
            ->assertOk()->json();
        $this->assertSame(1, $september['orders_count']);
        $this->assertSame('01/09/2026', $september['clients'][0]['orders'][0]['completed_on']);
        $this->assertSame($order->id, $september['clients'][0]['orders'][0]['id']);
    }

    public function test_order_without_work_type_gets_its_own_value_state(): void
    {
        $this->makeCompleted(['price_list_id' => $this->listId], [['man_hours' => 2]]);

        $report = $this->getJson('/api/v1/reports/lavori?from=2026-08-01&to=2026-08-31')
            ->assertOk()->json();

        $this->assertSame('no_work_type', $report['clients'][0]['orders'][0]['value_state']);
    }

    public function test_report_requires_a_period(): void
    {
        $this->getJson('/api/v1/reports/lavori')->assertUnprocessable();
        $this->getJson('/api/v1/reports/lavori?from=2026-08-31&to=2026-08-01')->assertUnprocessable();
    }
}
