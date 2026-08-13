<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IrrigationSystem;
use App\Models\Issue;
use App\Models\NonConformity;
use App\Models\WorkOrder;
use App\Services\Inspections\InspectionDeadlines;
use App\Support\IssueSla;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;

/**
 * Cruscotto "Oggi": tutto ciò che richiede attenzione, in un'unica
 * chiamata. Nessun dato nuovo: solo le viste già esistenti, filtrate
 * su ritardi e scadenze imminenti.
 */
class DashboardController extends Controller implements HasMiddleware
{
    private const TIMEZONE = 'Europe/Rome';

    /** Righe al massimo per sezione: il cruscotto segnala, le pagine elencano. */
    private const LIMIT = 10;

    public static function middleware(): array
    {
        return [new Middleware('can:works.view')];
    }

    public function today(InspectionDeadlines $deadlines): JsonResponse
    {
        $today = Carbon::now(self::TIMEZONE)->startOfDay();

        return response()->json(['data' => [
            'date' => $today->toDateString(),
            'work_orders' => $this->workOrders($today),
            'inspections' => $this->inspections($deadlines),
            'issues' => $this->issues(),
            'non_conformities' => $this->nonConformities(),
            'irrigation' => $this->irrigation($today),
        ]]);
    }

    /** In ritardo (fine prevista superata) e in programma nei prossimi 7 giorni. */
    private function workOrders(Carbon $today): array
    {
        $base = fn () => WorkOrder::query()
            ->with('area:id,name')
            ->whereIn('status', WorkOrder::FIELD_STATUSES);

        $present = fn ($order) => [
            'id' => $order->id,
            'code' => $order->code,
            'title' => $order->title,
            'status' => $order->status,
            'planned_start' => $order->planned_start?->toDateString(),
            'planned_end' => $order->planned_end?->toDateString(),
            'area' => $order->area?->name,
        ];

        $overdueQuery = $base()->whereNotNull('planned_end')
            ->whereDate('planned_end', '<', $today->toDateString());
        $weekQuery = $base()
            ->whereNotNull('planned_start')
            ->whereDate('planned_start', '<=', $today->copy()->addDays(7)->toDateString())
            ->where(fn ($q) => $q->whereNull('planned_end')
                ->orWhereDate('planned_end', '>=', $today->toDateString()));

        return [
            'overdue_count' => (clone $overdueQuery)->count(),
            'overdue' => $overdueQuery->orderBy('planned_end')->limit(self::LIMIT)->get()->map($present),
            'week' => $weekQuery->orderBy('planned_start')->limit(self::LIMIT)->get()->map($present),
        ];
    }

    /** Controlli ricorrenti scaduti o in scadenza entro 14 giorni. */
    private function inspections(InspectionDeadlines $deadlines): array
    {
        $all = $deadlines->compute();
        $overdue = $all->where('state', 'overdue');
        $dueSoon = $all->filter(fn ($row) => $row['state'] === 'due_soon' && $row['days_left'] <= 14);

        return [
            'overdue_count' => $overdue->count(),
            'due_soon_count' => $dueSoon->count(),
            'rows' => $overdue->concat($dueSoon)->take(self::LIMIT)->values(),
        ];
    }

    /** Segnalazioni aperte con SLA fuori tempo o in scadenza entro 3 giorni. */
    private function issues(): array
    {
        $soon = now()->addDays(3);

        $rows = Issue::query()
            ->whereIn('status', ['open', 'in_charge'])
            ->where(function ($q) use ($soon) {
                // La scadenza che conta: presa in carico se aperta, chiusura poi
                $q->where(fn ($w) => $w->where('status', 'open')->where('taken_charge_due_at', '<=', $soon))
                    ->orWhere('sla_due_at', '<=', $soon);
            })
            ->orderByRaw('LEAST(COALESCE(taken_charge_due_at, sla_due_at), sla_due_at)')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Issue $issue) => [
                'id' => $issue->id,
                'code' => $issue->code,
                'severity' => $issue->severity,
                'status' => $issue->status,
                'description' => mb_strimwidth((string) $issue->description, 0, 90, '…'),
                'sla' => IssueSla::describe($issue),
            ]);

        return [
            'count' => $rows->count(),
            'rows' => $rows,
        ];
    }

    /** Non conformità non ancora chiuse. */
    private function nonConformities(): array
    {
        $query = NonConformity::query()->where('status', '!=', 'closed');

        return [
            'open_count' => (clone $query)->count(),
            'rows' => $query->orderBy('detected_on')->limit(self::LIMIT)->get()
                ->map(fn (NonConformity $nc) => [
                    'id' => $nc->id,
                    'code' => $nc->code,
                    'severity' => $nc->severity,
                    'status' => $nc->status,
                    'description' => mb_strimwidth((string) $nc->description, 0, 90, '…'),
                    'due_on' => $nc->due_on?->toDateString(),
                ]),
        ];
    }

    /** Stagione irrigua: impianti da invernare o da riaprire (entro 7 giorni). */
    private function irrigation(Carbon $today): array
    {
        $horizon = $today->copy()->addDays(7)->toDateString();

        $present = fn (IrrigationSystem $system, string $action, ?string $onDate) => [
            'id' => $system->id,
            'name' => $system->name,
            'area' => $system->area?->name,
            'action' => $action,
            'on_date' => $onDate,
        ];

        $toWinterize = IrrigationSystem::query()->with('area:id,name')
            ->where('status', 'active')
            ->whereNotNull('season_closes_on')
            ->whereDate('season_closes_on', '<=', $horizon)
            ->orderBy('season_closes_on')->limit(self::LIMIT)->get()
            ->map(fn ($s) => $present($s, 'winterize', $s->season_closes_on?->toDateString()));

        $toReopen = IrrigationSystem::query()->with('area:id,name')
            ->where('status', 'winterized')
            ->whereNotNull('season_opens_on')
            ->whereDate('season_opens_on', '<=', $horizon)
            ->where(fn ($q) => $q->whereNull('season_closes_on')
                ->orWhereDate('season_closes_on', '>=', $today->toDateString()))
            ->orderBy('season_opens_on')->limit(self::LIMIT)->get()
            ->map(fn ($s) => $present($s, 'reopen', $s->season_opens_on?->toDateString()));

        return [
            'rows' => $toWinterize->concat($toReopen)->values(),
        ];
    }
}
