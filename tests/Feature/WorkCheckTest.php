<?php

namespace Tests\Feature;

use App\Models\NonConformity;
use App\Models\Team;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class WorkCheckTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser();
        $this->actingAsTenantUser($this->user);
    }

    private function makeOrder(string $status = 'completed', array $attributes = []): WorkOrder
    {
        return WorkOrder::create([
            'tenant_id' => $this->organization->id,
            'code' => 'ODL-2026-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'Da controllare',
            'status' => $status,
            'team_id' => Team::create(['tenant_id' => $this->organization->id, 'name' => 'S'.fake()->unique()->numberBetween(1, 999)])->id,
            ...$attributes,
        ]);
    }

    public function test_passed_check_is_recorded_without_nonconformity(): void
    {
        $order = $this->makeOrder();

        $detail = $this->postJson("/api/v1/work-orders/{$order->id}/checks", [
            'outcome' => 'passed',
            'notes' => 'Lavoro eseguito a regola d\'arte.',
        ])->assertOk()->json('data');

        $this->assertCount(1, $detail['checks']);
        $this->assertSame('passed', $detail['checks'][0]['outcome']);
        $this->assertCount(0, $detail['non_conformities']);
        $this->assertSame(0, NonConformity::query()->count());
    }

    public function test_failed_check_requires_nonconformity_details(): void
    {
        $order = $this->makeOrder();

        $this->postJson("/api/v1/work-orders/{$order->id}/checks", ['outcome' => 'failed'])
            ->assertUnprocessable();
    }

    public function test_failed_check_opens_numbered_nonconformity(): void
    {
        $order = $this->makeOrder();

        $detail = $this->postJson("/api/v1/work-orders/{$order->id}/checks", [
            'outcome' => 'failed',
            'notes' => 'Sfalcio incompleto lungo la recinzione.',
            'non_conformity' => [
                'severity' => 'major',
                'description' => 'Fascia di 2 metri non sfalciata lungo il lato nord.',
                'due_on' => now()->addDays(7)->toDateString(),
            ],
        ])->assertOk()->json('data');

        $this->assertSame('failed', $detail['checks'][0]['outcome']);
        $nc = NonConformity::query()->sole();
        $this->assertSame('NC-'.now()->year.'-0001', $nc->code);
        $this->assertSame('work_check', $nc->origin);
        $this->assertSame($order->id, $nc->work_order_id);
        $this->assertSame('open', $nc->status);
        // L'ordine controllato NON cambia stato: la storia resta intatta
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_corrective_order_is_generated_with_assets_of_the_checked_one(): void
    {
        $type = $this->makeObjectType($this->organization, 'P');
        $area = $this->createArea($this->organization);
        $assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'census_code' => 'QC-EL-1',
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');

        $order = $this->makeOrder('completed', ['area_id' => $area->id]);
        \App\Models\WorkOrderAsset::create([
            'tenant_id' => $this->organization->id,
            'work_order_id' => $order->id,
            'asset_id' => $assetId,
            'planned_quantity' => 1,
            'unit' => 'cad',
        ]);

        $this->postJson("/api/v1/work-orders/{$order->id}/checks", [
            'outcome' => 'failed',
            'non_conformity' => [
                'severity' => 'critical',
                'description' => 'Potatura da rifare su tutta l\'area.',
                'create_corrective_order' => true,
            ],
        ])->assertOk();

        $nc = NonConformity::query()->sole();
        $corrective = WorkOrder::query()->where('origin', 'non_conformity')->sole();
        $this->assertSame($nc->id, $corrective->origin_id);
        $this->assertSame('draft', $corrective->status);
        $this->assertSame('urgent', $corrective->priority);
        $this->assertSame($order->team_id, $corrective->team_id);
        $this->assertStringContainsString($nc->code, $corrective->title);
        $this->assertSame($assetId, $corrective->assets()->sole()->asset_id);
    }

    public function test_checks_only_on_real_work_states(): void
    {
        $draft = $this->makeOrder('draft');

        $this->postJson("/api/v1/work-orders/{$draft->id}/checks", ['outcome' => 'passed'])
            ->assertUnprocessable();
    }

    public function test_nonconformity_workflow_and_closure(): void
    {
        $order = $this->makeOrder();
        $this->postJson("/api/v1/work-orders/{$order->id}/checks", [
            'outcome' => 'failed',
            'non_conformity' => ['severity' => 'minor', 'description' => 'Residui non raccolti.'],
        ])->assertOk();
        $nc = NonConformity::query()->sole();

        // open -> verified non è ammesso
        $this->patchJson("/api/v1/non-conformities/{$nc->id}", ['status' => 'verified'])
            ->assertUnprocessable();

        // Percorso completo: open -> action -> verified -> closed
        $this->patchJson("/api/v1/non-conformities/{$nc->id}", [
            'status' => 'action',
            'root_cause' => 'Squadra senza soffiatore a disposizione.',
            'corrective_action' => 'Raccolta residui e verifica attrezzatura.',
        ])->assertOk();
        $this->patchJson("/api/v1/non-conformities/{$nc->id}", ['status' => 'verified'])->assertOk();
        $this->patchJson("/api/v1/non-conformities/{$nc->id}", ['status' => 'closed'])
            ->assertOk()->assertJsonPath('data.status', 'closed');

        $this->assertNotNull($nc->fresh()->closed_at);

        // Chiusa = immutabile
        $this->patchJson("/api/v1/non-conformities/{$nc->id}", ['root_cause' => 'Riscritta'])
            ->assertUnprocessable();
    }

    public function test_operator_reads_but_cannot_check_or_update(): void
    {
        $order = $this->makeOrder();
        $this->postJson("/api/v1/work-orders/{$order->id}/checks", [
            'outcome' => 'failed',
            'non_conformity' => ['severity' => 'minor', 'description' => 'Bordo non rifinito.'],
        ])->assertOk();
        $nc = NonConformity::query()->sole();

        [, $operator] = [null, \App\Models\User::factory()->create(['tenant_id' => $this->organization->id])];
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $operator->assignRole('operatore');
        $this->actingAsTenantUser($operator);

        $this->getJson('/api/v1/non-conformities')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/work-orders/{$order->id}/checks", ['outcome' => 'passed'])->assertForbidden();
        $this->patchJson("/api/v1/non-conformities/{$nc->id}", ['status' => 'action'])->assertForbidden();
    }

    public function test_nonconformities_are_tenant_isolated(): void
    {
        $order = $this->makeOrder();
        $this->postJson("/api/v1/work-orders/{$order->id}/checks", [
            'outcome' => 'failed',
            'non_conformity' => ['severity' => 'minor', 'description' => 'Riservata.'],
        ])->assertOk();

        [, $otherUser] = $this->createTenantUser();
        $this->actingAsTenantUser($otherUser);

        $this->getJson('/api/v1/non-conformities')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/v1/non-conformities/'.NonConformity::withoutGlobalScopes()->sole()->id, ['status' => 'action'])
            ->assertNotFound();
    }
}
