<?php

namespace App\Services\Notifications;

use App\Models\Certificate;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Inspections\InspectionDeadlines;
use App\Support\IssueSla;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Riepilogo email delle scadenze: la stessa sostanza del cruscotto Oggi,
 * recapitata ogni mattina a amministratori e tecnici che la vogliono.
 * Se non c'è nulla da segnalare, nessuna email: una busta vuota al giorno
 * insegna solo a ignorare le buste.
 */
class DailyDigest
{
    private const TIMEZONE = 'Europe/Rome';

    /** Righe al massimo per sezione: il dettaglio sta nelle pagine. */
    private const LIMIT = 10;

    public function __construct(private InspectionDeadlines $deadlines) {}

    /** Amministratori e tecnici attivi, con la preferenza email accesa. */
    public function recipients(Organization $organization): Collection
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);

        return User::withoutGlobalScopes()
            ->where('tenant_id', $organization->id)
            ->where('user_type', 'internal')
            ->where('is_active', true)
            ->where('notify_email', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $user->hasAnyRole(['amministratore', 'tecnico']))
            ->values();
    }

    /**
     * I dati del riepilogo per un tenant, o null se non c'è nulla da dire.
     * Le query di dominio contano sul TenantScope: qui si autentica
     * temporaneamente un destinatario, così la logica resta una sola
     * (stesse regole del cruscotto Oggi e delle pagine).
     */
    public function build(Organization $organization, User $actingAs): ?array
    {
        $previous = Auth::user();
        Auth::setUser($actingAs);
        app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);

        try {
            $today = Carbon::now(self::TIMEZONE)->startOfDay();

            $sections = array_filter([
                'certificates' => $this->certificates($today),
                'vta' => $this->vta($organization->id, $today),
                'inspections' => $this->inspections(),
                'issues' => $this->issues(),
                'work_orders' => $this->workOrders($today),
            ]);
        } finally {
            // Mai lasciare il tenant "aperto": la prossima iterazione
            // ripartirà dal proprio utente
            if ($previous !== null) {
                Auth::setUser($previous);
            } else {
                Auth::forgetUser();
            }
        }

        return $sections === [] ? null : [
            'date' => $today->toDateString(),
            'sections' => $sections,
        ];
    }

    /** Patentini e certificati scaduti o in scadenza entro 60 giorni. */
    private function certificates(Carbon $today): ?array
    {
        $horizon = $today->copy()->addDays(Certificate::DUE_SOON_DAYS)->toDateString();
        $query = Certificate::query()
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $horizon);

        $rows = (clone $query)->orderBy('expires_on')->limit(self::LIMIT)->get()
            ->map(fn (Certificate $c) => [
                'holder_name' => $c->holder_name,
                'title' => $c->title,
                'expires_on' => $c->expires_on->toDateString(),
                'state' => $c->state(),
            ]);

        return $rows->isEmpty() ? null : [
            'count' => (clone $query)->count(),
            'rows' => $rows,
        ];
    }

    /** Ricontrolli VTA scaduti o dovuti entro 30 giorni (alberi in piedi). */
    private function vta(string $tenantId, Carbon $today): ?array
    {
        $horizon = $today->copy()->addDays(30)->toDateString();

        $rows = collect(DB::select(<<<'SQL'
            SELECT latest.next_check_due, latest.failure_class, a.census_code
            FROM (
              SELECT DISTINCT ON (ta.tree_id)
                     ta.tree_id, ta.next_check_due, ta.failure_class
              FROM tree_assessments ta
              WHERE ta.tenant_id = ? AND ta.deleted_at IS NULL
              ORDER BY ta.tree_id, ta.assessed_on DESC, ta.created_at DESC
            ) latest
            JOIN assets a ON a.id = latest.tree_id AND a.deleted_at IS NULL
            JOIN trees t ON t.asset_id = latest.tree_id AND t.removed_on IS NULL
            WHERE latest.next_check_due IS NOT NULL AND latest.next_check_due <= ?
            ORDER BY latest.next_check_due
            SQL, [$tenantId, $horizon]));

        return $rows->isEmpty() ? null : [
            'count' => $rows->count(),
            'rows' => $rows->take(self::LIMIT)->map(fn ($r) => [
                'census_code' => $r->census_code,
                'failure_class' => $r->failure_class,
                'next_check_due' => $r->next_check_due,
                'overdue' => $r->next_check_due < $today->toDateString(),
            ])->values(),
        ];
    }

    /** Controlli ricorrenti scaduti o in scadenza entro 14 giorni. */
    private function inspections(): ?array
    {
        $all = $this->deadlines->compute();
        $due = $all->filter(fn ($row) => $row['state'] === 'overdue'
            || ($row['state'] === 'due_soon' && $row['days_left'] <= 14))->values();

        return $due->isEmpty() ? null : [
            'count' => $due->count(),
            'rows' => $due->take(self::LIMIT)->map(fn ($row) => [
                'template_name' => $row['template_name'],
                'target_label' => $row['target_label'],
                'due_date' => $row['due_date'],
                'overdue' => $row['state'] === 'overdue',
            ])->values(),
        ];
    }

    /** Segnalazioni con scadenze SLA superate o entro 3 giorni. */
    private function issues(): ?array
    {
        $soon = now()->addDays(3);

        $query = Issue::query()
            ->whereIn('status', ['open', 'in_charge'])
            ->where(function ($q) use ($soon) {
                $q->where(fn ($w) => $w->where('status', 'open')->where('taken_charge_due_at', '<=', $soon))
                    ->orWhere('sla_due_at', '<=', $soon);
            });

        $rows = (clone $query)
            ->orderByRaw("CASE WHEN status = 'open' THEN COALESCE(taken_charge_due_at, sla_due_at) ELSE sla_due_at END")
            ->limit(self::LIMIT)->get()
            ->map(fn (Issue $issue) => [
                'code' => $issue->code,
                'severity' => $issue->severity,
                'description' => mb_strimwidth((string) $issue->description, 0, 80, '…'),
                'sla' => IssueSla::describe($issue),
                'status' => $issue->status,
            ]);

        return $rows->isEmpty() ? null : [
            'count' => $query->count(),
            'rows' => $rows,
        ];
    }

    /** Ordini di lavoro oltre la fine prevista. */
    private function workOrders(Carbon $today): ?array
    {
        $query = WorkOrder::query()
            ->whereIn('status', WorkOrder::FIELD_STATUSES)
            ->whereNotNull('planned_end')
            ->whereDate('planned_end', '<', $today->toDateString());

        $rows = (clone $query)->orderBy('planned_end')->limit(self::LIMIT)->get()
            ->map(fn (WorkOrder $order) => [
                'code' => $order->code,
                'title' => $order->title,
                'planned_end' => $order->planned_end?->toDateString(),
            ]);

        return $rows->isEmpty() ? null : [
            'count' => (clone $query)->count(),
            'rows' => $rows,
        ];
    }
}
