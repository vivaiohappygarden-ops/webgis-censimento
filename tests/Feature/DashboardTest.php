<?php

namespace Tests\Feature;

use App\Models\IrrigationSystem;
use App\Models\Issue;
use App\Models\WorkOrder;
use App\Support\IssueSla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use InteractsWithTenant, RefreshDatabase;

    private $organization;

    private $user;

    private $area;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->user] = $this->createTenantUser();
        $this->actingAsTenantUser($this->user);
        $this->area = $this->createArea($this->organization);
    }

    private function makeWorkOrder(array $attributes = []): WorkOrder
    {
        return WorkOrder::create([
            'tenant_id' => $this->organization->id,
            'code' => WorkOrder::nextCode($this->organization->id),
            'title' => 'Lavoro di prova',
            'status' => 'planned',
            'area_id' => $this->area->id,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
            ...$attributes,
        ]);
    }

    public function test_dashboard_flags_overdue_and_upcoming_work(): void
    {
        // In ritardo: doveva chiudersi ieri
        $late = $this->makeWorkOrder([
            'title' => 'Potatura in ritardo',
            'planned_start' => now()->subDays(5)->toDateString(),
            'planned_end' => now()->subDay()->toDateString(),
        ]);
        // In programma questa settimana
        $this->makeWorkOrder([
            'title' => 'Sfalcio in settimana',
            'planned_start' => now()->addDays(2)->toDateString(),
            'planned_end' => now()->addDays(3)->toDateString(),
        ]);
        // Oltre l'orizzonte: non compare
        $this->makeWorkOrder([
            'title' => 'Lontano nel tempo',
            'planned_start' => now()->addDays(20)->toDateString(),
        ]);
        // Completato: non conta come ritardo
        $this->makeWorkOrder([
            'title' => 'Chiuso bene',
            'status' => 'completed',
            'planned_end' => now()->subDays(3)->toDateString(),
        ]);

        $body = $this->getJson('/api/v1/dashboard/today')->assertOk()->json('data');

        $this->assertSame(1, $body['work_orders']['overdue_count']);
        $this->assertSame($late->code, $body['work_orders']['overdue'][0]['code']);
        $weekTitles = array_column($body['work_orders']['week'], 'title');
        $this->assertContains('Sfalcio in settimana', $weekTitles);
        $this->assertNotContains('Lontano nel tempo', $weekTitles);
    }

    public function test_dashboard_flags_sla_issues_and_irrigation_season(): void
    {
        // Segnalazione grave aperta da 10 giorni: presa in carico fuori tempo
        $openedAt = now()->subDays(10);
        $issue = new Issue([
            'tenant_id' => $this->organization->id,
            'code' => Issue::nextCode($this->organization->id),
            'status' => 'open',
            'severity' => 'high',
            'reporter_type' => 'internal',
            'channel' => 'backoffice',
            'description' => 'Perdita alla valvola principale.',
            'sla_due_at' => IssueSla::resolveDueAt($openedAt, 'high'),
            'taken_charge_due_at' => IssueSla::takeChargeDueAt($openedAt, 'high'),
        ]);
        $issue->created_at = $openedAt;
        $issue->save();

        // Risolta: non deve comparire
        Issue::create([
            'tenant_id' => $this->organization->id,
            'code' => Issue::nextCode($this->organization->id),
            'status' => 'resolved',
            'severity' => 'high',
            'reporter_type' => 'internal',
            'channel' => 'backoffice',
            'description' => 'Già sistemata.',
            'resolved_at' => now(),
            'resolution_notes' => 'Fatto.',
        ]);

        // Impianto attivo con stagione ormai chiusa: da invernare
        IrrigationSystem::create([
            'tenant_id' => $this->organization->id,
            'area_id' => $this->area->id,
            'name' => 'Impianto da invernare',
            'status' => 'active',
            'season_opens_on' => now()->subMonths(6)->toDateString(),
            'season_closes_on' => now()->addDays(3)->toDateString(),
        ]);
        // Impianto invernato con apertura vicina: da riaprire
        IrrigationSystem::create([
            'tenant_id' => $this->organization->id,
            'area_id' => $this->area->id,
            'name' => 'Impianto da riaprire',
            'status' => 'winterized',
            'season_opens_on' => now()->addDays(5)->toDateString(),
            'season_closes_on' => now()->addMonths(6)->toDateString(),
        ]);
        // Fuori servizio: mai proposto
        IrrigationSystem::create([
            'tenant_id' => $this->organization->id,
            'area_id' => $this->area->id,
            'name' => 'Impianto rotto',
            'status' => 'out_of_service',
            'season_closes_on' => now()->addDays(2)->toDateString(),
        ]);

        $body = $this->getJson('/api/v1/dashboard/today')->assertOk()->json('data');

        $this->assertSame(1, $body['issues']['count']);
        $this->assertSame($issue->code, $body['issues']['rows'][0]['code']);
        $this->assertSame('overdue', $body['issues']['rows'][0]['sla']['take_charge']['state']);

        $actions = collect($body['irrigation']['rows'])->pluck('action', 'name');
        $this->assertSame('winterize', $actions['Impianto da invernare']);
        $this->assertSame('reopen', $actions['Impianto da riaprire']);
        $this->assertArrayNotHasKey('Impianto rotto', $actions->all());
    }

    public function test_issue_ordering_uses_the_deadline_that_matters(): void
    {
        // In carico da tempo, risoluzione tra 2 giorni: la presa in carico
        // (conclusa settimane fa) non deve pesare sull'ordinamento
        $old = now()->subDays(20);
        $inCharge = new Issue([
            'tenant_id' => $this->organization->id,
            'code' => Issue::nextCode($this->organization->id),
            'status' => 'in_charge',
            'severity' => 'low',
            'reporter_type' => 'internal',
            'channel' => 'backoffice',
            'description' => 'Presa in carico da tempo.',
            'taken_charge_at' => $old->copy()->addDay(),
            'sla_due_at' => now()->addDays(2),
            'taken_charge_due_at' => IssueSla::takeChargeDueAt($old, 'low'),
        ]);
        $inCharge->created_at = $old;
        $inCharge->save();

        // Aperta con presa in carico scaduta ieri: è lei l'urgenza
        $critical = new Issue([
            'tenant_id' => $this->organization->id,
            'code' => Issue::nextCode($this->organization->id),
            'status' => 'open',
            'severity' => 'critical',
            'reporter_type' => 'internal',
            'channel' => 'backoffice',
            'description' => 'Critica non ancora presa in carico.',
            'sla_due_at' => now()->addDays(2),
            'taken_charge_due_at' => now()->subDay(),
        ]);
        $critical->created_at = now()->subDays(2);
        $critical->save();

        $body = $this->getJson('/api/v1/dashboard/today')->assertOk()->json('data');
        $this->assertSame(2, $body['issues']['count']);
        $this->assertSame($critical->code, $body['issues']['rows'][0]['code']);
    }

    public function test_issue_count_is_the_total_not_the_page_size(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $issue = new Issue([
                'tenant_id' => $this->organization->id,
                'code' => Issue::nextCode($this->organization->id),
                'status' => 'open',
                'severity' => 'high',
                'reporter_type' => 'internal',
                'channel' => 'backoffice',
                'description' => "Segnalazione {$i} fuori tempo.",
                'sla_due_at' => now()->subDay(),
                'taken_charge_due_at' => now()->subDays(2),
            ]);
            $issue->created_at = now()->subDays(10);
            $issue->save();
        }

        $body = $this->getJson('/api/v1/dashboard/today')->assertOk()->json('data');
        $this->assertSame(12, $body['issues']['count']);
        $this->assertCount(10, $body['issues']['rows']);
    }

    public function test_week_follows_agenda_semantics_and_irrigation_needs_areas_view(): void
    {
        // Iniziato mesi fa senza fine prevista: occupa solo il giorno di
        // inizio (come in agenda), non resta "in settimana" per sempre
        $this->makeWorkOrder([
            'title' => 'Cantiere eterno',
            'status' => 'in_progress',
            'planned_start' => now()->subMonths(3)->toDateString(),
        ]);
        // In corso con fine futura: legittimamente in settimana
        $this->makeWorkOrder([
            'title' => 'Cantiere in corso',
            'status' => 'in_progress',
            'planned_start' => now()->subDays(10)->toDateString(),
            'planned_end' => now()->addDays(10)->toDateString(),
        ]);

        $body = $this->getJson('/api/v1/dashboard/today')->assertOk()->json('data');
        $titles = array_column($body['work_orders']['week'], 'title');
        $this->assertNotContains('Cantiere eterno', $titles);
        $this->assertContains('Cantiere in corso', $titles);
        $this->assertSame(1, $body['work_orders']['week_count']);

        // Chi ha works.view ma non areas.view non riceve la sezione irrigazione
        $viewer = \App\Models\User::factory()->create(['tenant_id' => $this->organization->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $viewer->givePermissionTo('works.view');
        $this->actingAsTenantUser($viewer);
        $body = $this->getJson('/api/v1/dashboard/today')->assertOk()->json('data');
        $this->assertNull($body['irrigation']);
    }

    public function test_dashboard_requires_works_view_and_isolates_tenants(): void
    {
        $this->makeWorkOrder([
            'planned_start' => now()->subDays(5)->toDateString(),
            'planned_end' => now()->subDay()->toDateString(),
        ]);

        // Il cliente del portale non vede il cruscotto
        [, $portalUser] = [null, \App\Models\User::factory()->create(['tenant_id' => $this->organization->id])];
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($this->organization->id);
        $portalUser->assignRole('cliente');
        $this->actingAsTenantUser($portalUser);
        $this->getJson('/api/v1/dashboard/today')->assertForbidden();

        // Un altro tenant vede un cruscotto vuoto, non i miei ritardi
        [, $foreign] = $this->createTenantUser();
        $this->actingAsTenantUser($foreign);
        $body = $this->getJson('/api/v1/dashboard/today')->assertOk()->json('data');
        $this->assertSame(0, $body['work_orders']['overdue_count']);
        $this->assertSame([], $body['work_orders']['week']);
    }
}
