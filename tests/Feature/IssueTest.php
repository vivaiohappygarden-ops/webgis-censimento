<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class IssueTest extends TestCase
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

    public function test_issues_get_sequential_codes_and_client_reports_need_a_name(): void
    {
        $first = $this->postJson('/api/v1/issues', ['description' => 'Ramo spezzato sul vialetto.'])
            ->assertCreated()->json('data');
        $this->assertSame('SEG-'.now()->year.'-0001', $first['code']);
        $this->assertSame('open', $first['status']);
        $this->assertSame('internal', $first['reporter_type']);

        // Segnalazione cliente senza nome rifiutata
        $this->postJson('/api/v1/issues', [
            'description' => 'Prato rovinato.', 'reporter_type' => 'client',
        ])->assertUnprocessable();

        $second = $this->postJson('/api/v1/issues', [
            'description' => 'Prato rovinato vicino ai giochi.',
            'reporter_type' => 'client',
            'reporter_name' => 'Condominio Girasoli',
            'severity' => 'high',
        ])->assertCreated()->json('data');
        $this->assertSame('SEG-'.now()->year.'-0002', $second['code']);
    }

    public function test_workflow_take_charge_and_resolution_with_notes(): void
    {
        $id = $this->postJson('/api/v1/issues', ['description' => 'Irrigatore rotto.'])->json('data.id');

        // open -> resolved diretto non ammesso
        $this->patchJson("/api/v1/issues/{$id}", ['status' => 'resolved'])->assertUnprocessable();

        $this->patchJson("/api/v1/issues/{$id}", ['status' => 'in_charge'])
            ->assertOk()->assertJsonPath('data.status', 'in_charge');
        $this->assertNotNull(Issue::query()->findOrFail($id)->taken_charge_at);

        // Risoluzione senza spiegazione rifiutata
        $this->patchJson("/api/v1/issues/{$id}", ['status' => 'resolved'])->assertUnprocessable();

        $this->patchJson("/api/v1/issues/{$id}", [
            'status' => 'resolved',
            'resolution_notes' => 'Sostituito l\'irrigatore e verificata la linea.',
        ])->assertOk()->assertJsonPath('data.status', 'resolved');

        // Risolta = immutabile
        $this->patchJson("/api/v1/issues/{$id}", ['severity' => 'low'])->assertUnprocessable();
    }

    public function test_work_order_generated_from_issue_once(): void
    {
        $type = $this->makeObjectType($this->organization, 'P');
        $area = $this->createArea($this->organization);
        $assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $area->id,
            'object_type_id' => $type->id,
            'census_code' => 'SEG-EL-1',
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');

        $issueId = $this->postJson('/api/v1/issues', [
            'description' => 'Pianta pericolante dopo il temporale.',
            'severity' => 'critical',
            'asset_id' => $assetId,
            'area_id' => $area->id,
        ])->json('data.id');

        $issue = $this->postJson("/api/v1/issues/{$issueId}/work-order")
            ->assertOk()->json('data');

        // Presa in carico implicita e ordine collegato
        $this->assertSame('in_charge', $issue['status']);
        $this->assertNotNull($issue['work_order']);

        $workOrder = WorkOrder::query()->findOrFail($issue['work_order']['id']);
        $this->assertSame('issue', $workOrder->origin);
        $this->assertSame($issueId, $workOrder->origin_id);
        $this->assertSame('urgent', $workOrder->priority);
        $this->assertSame($area->id, $workOrder->area_id);
        $this->assertSame($assetId, $workOrder->assets()->sole()->asset_id);

        // Una sola generazione per segnalazione
        $this->postJson("/api/v1/issues/{$issueId}/work-order")->assertUnprocessable();
    }

    public function test_operator_can_report_but_not_manage(): void
    {
        $issueId = $this->postJson('/api/v1/issues', ['description' => 'Da gestire.'])->json('data.id');

        [, $operator] = [null, \App\Models\User::factory()->create(['tenant_id' => $this->organization->id])];
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $operator->assignRole('operatore');
        $this->actingAsTenantUser($operator);

        // L'operatore vede e apre segnalazioni dal campo
        $this->getJson('/api/v1/issues')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/issues', ['description' => 'Buca nel prato area giochi.'])->assertCreated();

        // Ma non gestisce né genera ordini
        $this->patchJson("/api/v1/issues/{$issueId}", ['status' => 'in_charge'])->assertForbidden();
        $this->postJson("/api/v1/issues/{$issueId}/work-order")->assertForbidden();
    }

    public function test_issues_are_tenant_isolated(): void
    {
        $issueId = $this->postJson('/api/v1/issues', ['description' => 'Riservata.'])->json('data.id');

        [, $otherUser] = $this->createTenantUser();
        $this->actingAsTenantUser($otherUser);

        $this->getJson('/api/v1/issues')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/v1/issues/{$issueId}", ['status' => 'in_charge'])->assertNotFound();

        // Numerazione indipendente per organizzazione
        $this->postJson('/api/v1/issues', ['description' => 'Altro tenant.'])
            ->assertCreated()->assertJsonPath('data.code', 'SEG-'.now()->year.'-0001');
    }
}
