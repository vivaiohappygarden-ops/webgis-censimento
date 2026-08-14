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

    public function today(\Illuminate\Http\Request $request, InspectionDeadlines $deadlines): JsonResponse
    {
        $today = Carbon::now(self::TIMEZONE)->startOfDay();

        return response()->json(['data' => [
            'date' => $today->toDateString(),
            'work_orders' => $this->workOrders($today),
            'inspections' => $this->inspections($deadlines),
            'issues' => $this->issues(),
            'non_conformities' => $this->nonConformities(),
            'certificates' => $this->certificates($today),
            // La pagina e le API dell'irrigazione richiedono areas.view: chi
            // non le può aprire non deve vederne i dati nel cruscotto
            'irrigation' => $request->user()->can('areas.view') ? $this->irrigation($today) : null,
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
        // Stessa semantica dell'agenda: un ordine senza fine prevista occupa
        // il solo giorno di inizio, non resta "in settimana" per sempre
        $weekQuery = $base()
            ->whereNotNull('planned_start')
            ->whereDate('planned_start', '<=', $today->copy()->addDays(7)->toDateString())
            ->whereRaw('COALESCE(planned_end, planned_start) >= ?', [$today->toDateString()]);

        return [
            'overdue_count' => (clone $overdueQuery)->count(),
            'overdue' => $overdueQuery->orderBy('planned_end')->limit(self::LIMIT)->get()->map($present),
            'week_count' => (clone $weekQuery)->count(),
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

        $query = Issue::query()
            ->whereIn('status', ['open', 'in_charge'])
            ->where(function ($q) use ($soon) {
                // La scadenza che conta: presa in carico se aperta, chiusura poi
                $q->where(fn ($w) => $w->where('status', 'open')->where('taken_charge_due_at', '<=', $soon))
                    ->orWhere('sla_due_at', '<=', $soon);
            });

        $rows = (clone $query)
            // Stessa scadenza del filtro: per le prese in carico conta
            // taken_charge_due_at, per le altre la chiusura
            ->orderByRaw("CASE WHEN status = 'open' THEN COALESCE(taken_charge_due_at, sla_due_at) ELSE sla_due_at END")
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
            // Il totale si conta prima del tetto: non è la dimensione della pagina
            'count' => $query->count(),
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

    /** Patentini e certificati scaduti o in scadenza (entro 60 giorni). */
    private function certificates(Carbon $today): array
    {
        $horizon = $today->copy()->addDays(\App\Models\Certificate::DUE_SOON_DAYS)->toDateString();

        $query = \App\Models\Certificate::query()
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $horizon);

        $rows = (clone $query)->orderBy('expires_on')->limit(self::LIMIT)->get()
            ->map(fn (\App\Models\Certificate $c) => [
                'id' => $c->id,
                'holder_name' => $c->holder_name,
                'title' => $c->title,
                'expires_on' => $c->expires_on->toDateString(),
                'state' => $c->state(),
            ]);

        return [
            'expired_count' => (clone $query)->whereDate('expires_on', '<', $today->toDateString())->count(),
            'due_soon_count' => (clone $query)->whereDate('expires_on', '>=', $today->toDateString())->count(),
            'rows' => $rows,
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
