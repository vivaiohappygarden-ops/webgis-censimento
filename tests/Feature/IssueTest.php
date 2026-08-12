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

    public function test_reopening_clears_take_charge_and_notes(): void
    {
        $id = $this->postJson('/api/v1/issues', ['description' => 'Da riaprire.'])->json('data.id');
        $this->patchJson("/api/v1/issues/{$id}", [
            'status' => 'in_charge',
            'resolution_notes' => 'Bozza di risoluzione.',
        ])->assertOk();

        $this->patchJson("/api/v1/issues/{$id}", ['status' => 'open'])->assertOk();

        $issue = Issue::query()->findOrFail($id);
        $this->assertNull($issue->taken_charge_at);
        $this->assertNull($issue->resolution_notes);

        // Dopo la riapertura la chiusura richiede note NUOVE
        $this->patchJson("/api/v1/issues/{$id}", ['status' => 'in_charge'])->assertOk();
        $this->patchJson("/api/v1/issues/{$id}", ['status' => 'resolved'])->assertUnprocessable();
    }

    public function test_malformed_filters_are_rejected_cleanly(): void
    {
        // Un parametro non stringa deve dare un errore pulito, non un errore interno
        $this->getJson('/api/v1/issues?q[]=x')->assertUnprocessable();

        // La gravità di default compare già nella risposta di creazione
        $this->postJson('/api/v1/issues', ['description' => 'Default.'])
            ->assertCreated()->assertJsonPath('data.severity', 'medium');
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

    public function test_sla_deadlines_follow_severity_and_track_delays(): void
    {
        // Critica: presa in carico entro 1 giorno, risoluzione entro 3
        $created = $this->postJson('/api/v1/issues', [
            'description' => 'Ramo pericolante sul passaggio pedonale.', 'severity' => 'critical',
        ])->assertCreated()->json('data');
        $this->assertSame('pending', $created['sla']['take_charge']['state']);
        $this->assertSame('pending', $created['sla']['resolve']['state']);

        $issue = Issue::query()->findOrFail($created['id']);
        $this->assertLessThan(2, abs($issue->sla_due_at->diffInSeconds($issue->created_at->copy()->addDays(3))));

        // Aperta da 2 giorni e mai presa in carico: fuori tempo massimo
        $issue->created_at = now()->subDays(2);
        $issue->sla_due_at = $issue->created_at->copy()->addDays(3);
        $issue->save();

        // Una lieve appena aperta invece è nei tempi e resta fuori dal filtro
        $this->postJson('/api/v1/issues', ['description' => 'Erba alta lungo la recinzione.', 'severity' => 'low'])
            ->assertCreated();

        $listed = $this->getJson('/api/v1/issues?sla=overdue')->assertOk()->json('data');
        $this->assertCount(1, $listed);
        $this->assertSame($issue->id, $listed[0]['id']);
        $this->assertSame('overdue', $listed[0]['sla']['take_charge']['state']);
        $this->assertSame(1, $listed[0]['sla']['take_charge']['days_late']);
        $this->assertSame('pending', $listed[0]['sla']['resolve']['state']);

        // Presa in carico tardiva, ma risoluzione entro la scadenza
        $data = $this->patchJson("/api/v1/issues/{$issue->id}", ['status' => 'in_charge'])->assertOk()->json('data');
        $this->assertSame('late', $data['sla']['take_charge']['state']);
        $data = $this->patchJson("/api/v1/issues/{$issue->id}", [
            'status' => 'resolved', 'resolution_notes' => 'Ramo rimosso in giornata.',
        ])->assertOk()->json('data');
        $this->assertSame('met', $data['sla']['resolve']['state']);

        // Risolta: non è più "fuori tempo massimo"
        $this->assertCount(0, $this->getJson('/api/v1/issues?sla=overdue')->json('data'));

        // Il filtro accetta solo valori conosciuti
        $this->getJson('/api/v1/issues?sla=qualsiasi')->assertUnprocessable();
    }

    public function test_severity_change_moves_resolution_deadline_from_opening(): void
    {
        $id = $this->postJson('/api/v1/issues', ['description' => 'Giostrina scheggiata.'])
            ->assertCreated()->json('data.id'); // media: 15 giorni

        $issue = Issue::query()->findOrFail($id);
        $this->assertLessThan(2, abs($issue->sla_due_at->diffInSeconds($issue->created_at->copy()->addDays(15))));

        // Riclassificata critica: la scadenza si accorcia, contata dall'apertura
        $this->patchJson("/api/v1/issues/{$id}", ['severity' => 'critical'])->assertOk();
        $issue->refresh();
        $this->assertLessThan(2, abs($issue->sla_due_at->diffInSeconds($issue->created_at->copy()->addDays(3))));

        // Le archiviate non hanno SLA: nessun intervento richiesto
        $data = $this->patchJson("/api/v1/issues/{$id}", ['status' => 'dismissed'])->assertOk()->json('data');
        $this->assertNull($data['sla']);
    }

    public function test_issue_created_from_field_via_sync_command(): void
    {
        [, $operator] = [null, \App\Models\User::factory()->create(['tenant_id' => $this->organization->id])];
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $operator->assignRole('operatore');
        $this->actingAsTenantUser($operator);

        $issueId = (string) \Illuminate\Support\Str::uuid();
        $command = [
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'device_seq' => 1,
            'type' => 'issue.create',
            'entity_id' => $issueId,
            'payload' => [
                'description' => 'Panchina divelta vicino al vialetto.',
                'severity' => 'high',
            ],
            'client_ts' => now()->toIso8601String(),
        ];
        $batch = [
            'batch_id' => (string) \Illuminate\Support\Str::uuid(),
            'device_id' => 'dev-test-0001',
            'schema' => 1,
            'commands' => [$command],
        ];

        $this->postJson('/api/v1/sync/batch', $batch)->assertOk()
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.0.entity_id', $issueId);

        $issue = Issue::query()->findOrFail($issueId);
        $this->assertSame('SEG-'.now()->year.'-0001', $issue->code);
        $this->assertSame('pwa', $issue->channel);
        $this->assertSame($operator->id, $issue->reporter_user_id);
        $this->assertSame('open', $issue->status);
        $this->assertSame('high', $issue->severity);
        // La data è quella dichiarata dal device, non quella della sync
        $this->assertLessThan(2, abs($issue->created_at->diffInSeconds(
            \Illuminate\Support\Carbon::parse($command['client_ts']),
        )));
        // E la scadenza di risoluzione decorre da lì (alta: 7 giorni)
        $this->assertLessThan(2, abs($issue->sla_due_at->diffInSeconds(
            $issue->created_at->copy()->addDays(7),
        )));

        // Replay dello stesso batch: nessun doppione
        $this->postJson('/api/v1/sync/batch', $batch)->assertOk()
            ->assertJsonPath('results.0.status', 'duplicate');
        $this->assertSame(1, Issue::query()->count());

        // Stesso entity_id con chiave nuova: collisione rifiutata col codice giusto
        $this->postJson('/api/v1/sync/batch', [
            ...$batch,
            'batch_id' => (string) \Illuminate\Support\Str::uuid(),
            'commands' => [[...$command, 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()]],
        ])->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'ID_COLLISION');

        // Un elemento di un'altra organizzazione è invisibile: rifiuto pulito
        [$otherOrg] = $this->createTenantUser();
        $foreignAsset = \App\Models\Asset::withoutGlobalScopes()->create([
            'tenant_id' => $otherOrg->id,
            'area_id' => $this->createArea($otherOrg)->id,
            'object_type_id' => $this->makeObjectType($otherOrg, 'P')->id,
            'geom' => \App\Support\Geometry::toEwkb($this->pointGeometry()),
        ]);
        $this->actingAsTenantUser($operator);
        $this->postJson('/api/v1/sync/batch', [
            ...$batch,
            'batch_id' => (string) \Illuminate\Support\Str::uuid(),
            'commands' => [[
                ...$command,
                'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
                'entity_id' => (string) \Illuminate\Support\Str::uuid(),
                'payload' => ['description' => 'Su elemento altrui.', 'asset_id' => $foreignAsset->id],
            ]],
        ])->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.code', 'VALIDATION_FAILED');
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
