<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class WorkOrderTest extends TestCase
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

    public function test_work_orders_get_sequential_codes_per_year(): void
    {
        $first = $this->postJson('/api/v1/work-orders', ['title' => 'Sfalcio primaverile'])
            ->assertCreated()->json('data');
        $second = $this->postJson('/api/v1/work-orders', ['title' => 'Potatura filare'])
            ->assertCreated()->json('data');

        $year = now()->year;
        $this->assertSame("ODL-{$year}-0001", $first['code']);
        $this->assertSame("ODL-{$year}-0002", $second['code']);
        $this->assertSame('draft', $first['status']);
    }

    public function test_state_machine_allows_only_valid_transitions(): void
    {
        $team = Team::create(['tenant_id' => $this->organization->id, 'name' => 'Squadra A']);
        $id = $this->postJson('/api/v1/work-orders', ['title' => 'Ordine', 'team_id' => $team->id])
            ->json('data.id');

        // draft -> completed non è ammesso
        $this->postJson("/api/v1/work-orders/{$id}/transition", ['status' => 'completed'])
            ->assertUnprocessable();

        // Percorso valido: draft -> planned -> assigned -> in_progress -> completed
        foreach (['planned', 'assigned', 'in_progress', 'completed'] as $status) {
            $this->postJson("/api/v1/work-orders/{$id}/transition", ['status' => $status])
                ->assertOk()->assertJsonPath('data.status', $status);
        }

        // Da completed non si esce
        $this->postJson("/api/v1/work-orders/{$id}/transition", ['status' => 'in_progress'])
            ->assertUnprocessable();
    }

    public function test_assignment_required_before_assigning(): void
    {
        $id = $this->postJson('/api/v1/work-orders', ['title' => 'Senza squadra'])->json('data.id');
        $this->postJson("/api/v1/work-orders/{$id}/transition", ['status' => 'planned'])->assertOk();

        // Senza squadra né responsabile il passaggio ad 'assigned' è rifiutato
        $this->postJson("/api/v1/work-orders/{$id}/transition", ['status' => 'assigned'])
            ->assertUnprocessable();
    }

    public function test_stale_version_is_rejected_with_conflict(): void
    {
        $id = $this->postJson('/api/v1/work-orders', ['title' => 'Concorrenza'])->json('data.id');

        $this->postJson("/api/v1/work-orders/{$id}/transition", ['status' => 'planned', 'version' => 1])
            ->assertOk();
        $this->postJson("/api/v1/work-orders/{$id}/transition", ['status' => 'cancelled', 'version' => 1])
            ->assertConflict();
    }

    public function test_assets_can_be_attached_and_detached(): void
    {
        $type = $this->makeObjectType($this->organization, 'P');
        $assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $type->id,
            'census_code' => 'WO-EL-1',
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');

        $woId = $this->postJson('/api/v1/work-orders', ['title' => 'Con elementi'])->json('data.id');

        $detail = $this->postJson("/api/v1/work-orders/{$woId}/assets", ['asset_id' => $assetId])
            ->assertOk()->json('data');
        $this->assertCount(1, $detail['assets']);
        $this->assertSame('WO-EL-1', $detail['assets'][0]['asset']['census_code']);

        // Doppione rifiutato
        $this->postJson("/api/v1/work-orders/{$woId}/assets", ['asset_id' => $assetId])
            ->assertUnprocessable();

        $this->deleteJson("/api/v1/work-orders/{$woId}/assets/{$detail['assets'][0]['id']}")
            ->assertOk()->assertJsonCount(0, 'data.assets');
    }

    public function test_update_cannot_remove_assignment_while_assigned(): void
    {
        $team = Team::create(['tenant_id' => $this->organization->id, 'name' => 'Squadra B']);
        $id = $this->postJson('/api/v1/work-orders', ['title' => 'Guardia', 'team_id' => $team->id])
            ->json('data.id');
        $this->postJson("/api/v1/work-orders/{$id}/transition", ['status' => 'planned'])->assertOk();
        $this->postJson("/api/v1/work-orders/{$id}/transition", ['status' => 'assigned'])->assertOk();

        // Togliere squadra e responsabile a ordine assegnato è rifiutato
        $this->patchJson("/api/v1/work-orders/{$id}", ['team_id' => null, 'assigned_to' => null])
            ->assertUnprocessable();

        $this->getJson("/api/v1/work-orders/{$id}")
            ->assertJsonPath('data.team_id', $team->id);
    }

    public function test_terminal_orders_are_immutable(): void
    {
        $id = $this->postJson('/api/v1/work-orders', ['title' => 'Chiuso'])->json('data.id');
        $this->postJson("/api/v1/work-orders/{$id}/transition", ['status' => 'cancelled'])->assertOk();

        $this->patchJson("/api/v1/work-orders/{$id}", ['title' => 'Riscritto'])
            ->assertUnprocessable();

        $type = $this->makeObjectType($this->organization, 'P');
        $assetId = $this->postJson('/api/v1/assets', [
            'area_id' => $this->area->id,
            'object_type_id' => $type->id,
            'geometry' => $this->pointGeometry(),
        ])->json('data.id');
        $this->postJson("/api/v1/work-orders/{$id}/assets", ['asset_id' => $assetId])
            ->assertUnprocessable();
    }

    public function test_operator_can_view_but_not_manage(): void
    {
        $this->postJson('/api/v1/work-orders', ['title' => 'Visibile'])->assertCreated();

        [, $operator] = [null, \App\Models\User::factory()->create(['tenant_id' => $this->organization->id])];
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $operator->assignRole('operatore');
        $this->actingAsTenantUser($operator);

        $this->getJson('/api/v1/work-orders')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/work-orders', ['title' => 'Vietato'])->assertForbidden();
    }

    public function test_agenda_window_returns_only_overlapping_orders(): void
    {
        $inWindow = $this->postJson('/api/v1/work-orders', [
            'title' => 'Dentro la settimana',
            'planned_start' => '2026-08-12', 'planned_end' => '2026-08-13',
        ])->json('data.id');
        $spanning = $this->postJson('/api/v1/work-orders', [
            'title' => 'A cavallo della finestra',
            'planned_start' => '2026-08-05', 'planned_end' => '2026-08-20',
        ])->json('data.id');
        $singleDay = $this->postJson('/api/v1/work-orders', [
            'title' => 'Un solo giorno, senza fine prevista',
            'planned_start' => '2026-08-14',
        ])->json('data.id');
        $this->postJson('/api/v1/work-orders', [
            'title' => 'Fuori finestra (dopo)',
            'planned_start' => '2026-08-20', 'planned_end' => '2026-08-22',
        ]);
        $this->postJson('/api/v1/work-orders', [
            'title' => 'Fuori finestra (terminato prima)',
            'planned_start' => '2026-08-01', 'planned_end' => '2026-08-05',
        ]);
        $this->postJson('/api/v1/work-orders', ['title' => 'Senza date']);

        $ids = collect($this->getJson('/api/v1/work-orders?from=2026-08-10&to=2026-08-16')
            ->assertOk()->json('data'))->pluck('id');

        $this->assertEqualsCanonicalizing([$inWindow, $spanning, $singleDay], $ids->all());

        // Finestra incoerente o monca rifiutata
        $this->getJson('/api/v1/work-orders?from=2026-08-16&to=2026-08-10')->assertUnprocessable();
        $this->getJson('/api/v1/work-orders?from=2026-08-10')->assertUnprocessable();
        $this->getJson('/api/v1/work-orders?to=2026-08-16')->assertUnprocessable();
    }

    public function test_unplanned_filter_returns_live_orders_without_start_date(): void
    {
        $unplanned = $this->postJson('/api/v1/work-orders', ['title' => 'Da pianificare'])->json('data.id');
        $this->postJson('/api/v1/work-orders', [
            'title' => 'Gia pianificato', 'planned_start' => '2026-08-12',
        ]);
        $cancelledId = $this->postJson('/api/v1/work-orders', ['title' => 'Annullato senza date'])->json('data.id');
        $this->postJson("/api/v1/work-orders/{$cancelledId}/transition", ['status' => 'cancelled'])->assertOk();

        $ids = collect($this->getJson('/api/v1/work-orders?unplanned=1')->assertOk()->json('data'))->pluck('id');

        $this->assertSame([$unplanned], $ids->all());
    }

    public function test_work_orders_are_tenant_isolated(): void
    {
        $id = $this->postJson('/api/v1/work-orders', ['title' => 'Riservato'])->json('data.id');

        [, $otherUser] = $this->createTenantUser();
        $this->actingAsTenantUser($otherUser);

        $this->getJson('/api/v1/work-orders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/work-orders/{$id}")->assertNotFound();

        // La numerazione riparte per ogni tenant
        $this->postJson('/api/v1/work-orders', ['title' => 'Altro tenant'])
            ->assertCreated()->assertJsonPath('data.code', 'ODL-'.now()->year.'-0001');
    }
}
