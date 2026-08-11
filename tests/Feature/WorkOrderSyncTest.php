<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * "I miei lavori" nella PWA operatore: working set degli ordini,
 * transizioni dal campo e consuntivi offline (work_log.add).
 */
class WorkOrderSyncTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $operator;

    private $team;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->operator] = $this->createTenantUser(role: 'operatore');
        $this->team = Team::create(['tenant_id' => $this->organization->id, 'name' => 'Squadra Uno']);
        DB::table('team_members')->insert([
            'team_id' => $this->team->id,
            'user_id' => $this->operator->id,
            'tenant_id' => $this->organization->id,
            'member_role' => 'operator',
        ]);
        $this->actingAsTenantUser($this->operator);
    }

    private function makeOrder(array $attributes = []): WorkOrder
    {
        return WorkOrder::create([
            'tenant_id' => $this->organization->id,
            'code' => 'ODL-2026-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'Ordine di prova',
            'status' => 'assigned',
            'team_id' => $this->team->id,
            ...$attributes,
        ]);
    }

    private function makeColleague(string $role = 'operatore'): User
    {
        $user = User::factory()->create(['tenant_id' => $this->organization->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $user->assignRole($role);

        return $user;
    }

    private function batchPayload(array $commands): array
    {
        return [
            'batch_id' => (string) Str::uuid(),
            'device_id' => 'dev-test-0001',
            'schema' => 1,
            'commands' => $commands,
        ];
    }

    private function transitionCommand(WorkOrder $order, string $status, int $baseVersion): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'device_seq' => 1,
            'type' => 'work_order.transition',
            'entity_id' => $order->id,
            'base_version' => $baseVersion,
            'payload' => ['status' => $status],
        ];
    }

    public function test_bootstrap_returns_only_orders_of_operator_in_field_statuses(): void
    {
        $mine = $this->makeOrder();
        $personal = $this->makeOrder(['team_id' => null, 'assigned_to' => $this->operator->id, 'status' => 'in_progress']);
        $this->makeOrder(['team_id' => null, 'assigned_to' => $this->makeColleague()->id]); // di altri
        $this->makeOrder(['status' => 'draft']); // non ancora sul campo
        $this->makeOrder(['status' => 'completed']); // già chiuso

        $rows = collect($this->getJson('/api/v1/sync/bootstrap')->assertOk()->json('work_orders'));

        $this->assertEqualsCanonicalizing(
            [$mine->id, $personal->id],
            $rows->pluck('id')->all(),
        );
        $this->assertSame('Squadra Uno', $rows->firstWhere('id', $mine->id)['team']['name']);
    }

    public function test_manager_bootstrap_sees_all_field_orders(): void
    {
        $this->makeOrder();
        $this->makeOrder(['team_id' => null, 'assigned_to' => $this->makeColleague()->id]);
        $this->makeOrder(['status' => 'draft']);

        $this->actingAsTenantUser($this->makeColleague('amministratore'));

        $this->getJson('/api/v1/sync/bootstrap')->assertOk()->assertJsonCount(2, 'work_orders');
    }

    public function test_operator_runs_order_lifecycle_with_worklog_from_the_field(): void
    {
        $order = $this->makeOrder();
        $logId = (string) Str::uuid();

        $response = $this->postJson('/api/v1/sync/batch', $this->batchPayload([
            $this->transitionCommand($order, 'in_progress', 1),
            [
                'idempotency_key' => (string) Str::uuid(),
                'device_seq' => 2,
                'type' => 'work_log.add',
                'entity_id' => $logId,
                'payload' => [
                    'work_order_id' => $order->id,
                    'started_at' => '2026-08-11T07:30:00+02:00',
                    'ended_at' => '2026-08-11T11:00:00+02:00',
                    'man_hours' => 7.0,
                    'quantity' => 1200,
                    'unit' => 'mq',
                    'notes' => 'Sfalcio completato su tutta l\'area.',
                ],
            ],
            $this->transitionCommand($order, 'completed', 2),
        ]))->assertOk();

        $response->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.1.status', 'applied')
            ->assertJsonPath('results.2.status', 'applied')
            ->assertJsonPath('results.2.version', 3);

        $order->refresh();
        $this->assertSame('completed', $order->status);

        $log = WorkLog::query()->findOrFail($logId);
        $this->assertSame($this->operator->id, $log->operator_id);
        $this->assertSame($this->team->id, $log->team_id);
        $this->assertSame('2026-08-11 05:30:00', $log->started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertEquals(7.0, $log->man_hours);
    }

    public function test_operator_cannot_act_on_foreign_orders_or_backoffice_transitions(): void
    {
        $foreign = $this->makeOrder(['team_id' => null, 'assigned_to' => $this->makeColleague()->id]);
        $planned = $this->makeOrder(['status' => 'planned']);

        $response = $this->postJson('/api/v1/sync/batch', $this->batchPayload([
            $this->transitionCommand($foreign, 'in_progress', 1),
            // planned -> assigned è ammesso dalla macchina a stati, ma solo dal backoffice
            $this->transitionCommand($planned, 'assigned', 1),
            [
                'idempotency_key' => (string) Str::uuid(),
                'device_seq' => 3,
                'type' => 'work_log.add',
                'entity_id' => (string) Str::uuid(),
                'payload' => ['work_order_id' => $foreign->id, 'started_at' => now()->toIso8601String()],
            ],
        ]))->assertOk();

        $response->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'FORBIDDEN')
            ->assertJsonPath('results.1.status', 'rejected')
            ->assertJsonPath('results.1.code', 'FORBIDDEN')
            ->assertJsonPath('results.2.status', 'rejected')
            ->assertJsonPath('results.2.code', 'FORBIDDEN');

        $this->assertSame('assigned', $foreign->fresh()->status);
        $this->assertSame(0, WorkLog::query()->count());
    }

    public function test_stale_base_version_returns_conflict_with_current_status(): void
    {
        $order = $this->makeOrder();

        $this->postJson('/api/v1/sync/batch', $this->batchPayload([
            $this->transitionCommand($order, 'in_progress', 5),
        ]))->assertOk()
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.code', 'VERSION_MISMATCH')
            ->assertJsonPath('results.0.theirs.fields.status', 'assigned');
    }

    public function test_worklog_with_incoherent_times_is_rejected(): void
    {
        $order = $this->makeOrder();

        $this->postJson('/api/v1/sync/batch', $this->batchPayload([[
            'idempotency_key' => (string) Str::uuid(),
            'device_seq' => 1,
            'type' => 'work_log.add',
            'entity_id' => (string) Str::uuid(),
            'payload' => [
                'work_order_id' => $order->id,
                'started_at' => '2026-08-11T10:00:00Z',
                'ended_at' => '2026-08-11T08:00:00Z',
            ],
        ]]))->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'VALIDATION_FAILED');
    }

    public function test_worklog_hours_beyond_column_range_are_rejected_not_internal(): void
    {
        $order = $this->makeOrder();

        // 10000 supererebbe numeric(6,2): deve essere un rifiuto pulito,
        // non un errore interno "ritentabile" che blocca la coda per sempre
        $this->postJson('/api/v1/sync/batch', $this->batchPayload([[
            'idempotency_key' => (string) Str::uuid(),
            'device_seq' => 1,
            'type' => 'work_log.add',
            'entity_id' => (string) Str::uuid(),
            'payload' => [
                'work_order_id' => $order->id,
                'started_at' => now()->toIso8601String(),
                'man_hours' => 10000,
            ],
        ]]))->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'VALIDATION_FAILED');

        $this->assertSame(0, WorkLog::query()->count());
    }

    public function test_joining_and_leaving_a_team_moves_its_orders_in_the_delta(): void
    {
        $order = $this->makeOrder();
        $cursor = $this->getJson('/api/v1/sync/bootstrap')->assertOk()->json('cursor');

        // L'uscita dalla squadra produce il tombstone per l'operatore rimosso
        DB::table('team_members')
            ->where('team_id', $this->team->id)
            ->where('user_id', $this->operator->id)
            ->update(['left_on' => now()->toDateString()]);

        $changes = collect($this->getJson('/api/v1/sync/changes?cursor='.$cursor)->assertOk()->json('changes'))
            ->where('table', 'work_orders');
        $this->assertSame($order->id, $changes->firstWhere('op', 'delete')['id']);

        // Il rientro in squadra rimette gli ordini nel delta come upsert
        $cursor = $this->getJson('/api/v1/sync/bootstrap')->assertOk()->json('cursor');
        DB::table('team_members')
            ->where('team_id', $this->team->id)
            ->where('user_id', $this->operator->id)
            ->update(['left_on' => null]);

        $changes = collect($this->getJson('/api/v1/sync/changes?cursor='.$cursor)->assertOk()->json('changes'))
            ->where('table', 'work_orders');
        $this->assertSame($order->id, $changes->firstWhere('op', 'upsert')['row']['id']);
    }

    public function test_changes_upserts_new_orders_and_tombstones_closed_ones(): void
    {
        $order = $this->makeOrder();
        $cursor = $this->getJson('/api/v1/sync/bootstrap')->assertOk()->json('cursor');

        // Il backoffice chiude l'ordine e ne pianifica uno nuovo per la squadra
        $order->update(['status' => 'completed']);
        $fresh = $this->makeOrder(['title' => 'Nuovo intervento']);

        $changes = collect($this->getJson('/api/v1/sync/changes?cursor='.$cursor)->assertOk()->json('changes'))
            ->where('table', 'work_orders');

        $tombstone = $changes->firstWhere('op', 'delete');
        $upsert = $changes->firstWhere('op', 'upsert');
        $this->assertSame($order->id, $tombstone['id']);
        $this->assertSame($fresh->id, $upsert['row']['id']);
        $this->assertSame('Nuovo intervento', $upsert['row']['title']);
    }
}
