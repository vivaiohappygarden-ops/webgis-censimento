<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RescheduleRequest;
use App\Models\WorkOrder;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Il portale dell'impresa appaltatrice: i soli ordini affidati alle squadre
 * esterne di cui l'utente fa parte, con la possibilita' di chiedere
 * formalmente una riprogrammazione con motivo codificato.
 *
 * Visibilita' a tempo: bozze e annullati mai; i completati restano visibili
 * per GIORNI_COMPLETATI giorni, poi escono dall'elenco.
 */
class ImpresaPortalController extends Controller implements HasMiddleware
{
    public const GIORNI_COMPLETATI = 30;

    public static function middleware(): array
    {
        return [new Middleware('can:impresa.view')];
    }

    public function ordini(Request $request): JsonResponse
    {
        $squadre = $this->squadreDellUtente($request);

        $ordini = $this->soloDelCommittente(WorkOrder::query(), $squadre)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->where(function ($q) {
                $q->where('status', '!=', 'completed')
                    ->orWhere('completed_at', '>=', now()->subDays(self::GIORNI_COMPLETATI));
            })
            ->with([
                'area:id,name,code,locality_id',
                'area.locality:id,name,site_id',
                'area.locality.site:id,name,municipality',
                'workType:id,name,unit',
            ])
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'assigned' THEN 1 WHEN 'planned' THEN 2 WHEN 'suspended' THEN 3 ELSE 4 END")
            ->orderBy('planned_start')
            ->get();

        // TUTTE le richieste recenti delle mie squadre, non solo quelle degli
        // ordini ancora in elenco: l'esito deve arrivare anche se l'ordine è
        // stato chiuso, riassegnato o è uscito per la visibilità a tempo
        $richieste = RescheduleRequest::query()
            ->whereIn('team_id', $squadre->pluck('id'))
            ->where(fn ($q) => $q->where('status', 'aperta')
                ->orWhere('created_at', '>=', now()->subDays(90)))
            ->with(['workOrder' => fn ($q) => $q->withTrashed()->select('id', 'code', 'title')])
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('work_order_id');

        $idVisibili = $ordini->pluck('id')->flip();
        $formattaRichiesta = fn ($r) => [
            'id' => $r->id,
            'reason' => $r->reason,
            'motivo' => RescheduleRequest::MOTIVI[$r->reason] ?? $r->reason,
            'proposed_start' => $r->proposed_start?->toDateString(),
            'notes' => $r->notes,
            'status' => $r->status,
            'response_note' => $r->response_note,
            'created_at' => $r->created_at?->toDateTimeString(),
        ];
        $fuoriElenco = $richieste
            ->reject(fn ($gruppo, $workOrderId) => $idVisibili->has($workOrderId))
            ->flatten(1)
            ->map(fn ($r) => $formattaRichiesta($r) + [
                'ordine_code' => $r->workOrder?->code,
                'ordine_title' => $r->workOrder?->title,
            ])
            ->values();

        return response()->json([
            'data' => $ordini->map(fn (WorkOrder $o) => [
                'id' => $o->id,
                'code' => $o->code,
                'title' => $o->title,
                'description' => $o->description,
                'status' => $o->status,
                'planned_start' => $o->planned_start?->toDateString(),
                'planned_end' => $o->planned_end?->toDateString(),
                'team_id' => $o->team_id,
                'work_type' => $o->workType?->name,
                'area' => $o->area?->name,
                'localita' => $o->area?->locality?->name,
                'comune' => $o->area?->locality?->site?->municipality,
                'risks' => $o->risks,
                'ppe' => $o->ppe,
                'richieste' => ($richieste[$o->id] ?? collect())->map($formattaRichiesta)->values(),
            ])->values(),
            'fuori_elenco' => $fuoriElenco,
            'squadre' => $squadre->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
            'stati' => WorkOrder::STATUS_LABELS,
            'motivi' => RescheduleRequest::MOTIVI,
        ]);
    }

    public function chiediRiprogrammazione(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(RescheduleRequest::MOTIVI))],
            'proposed_start' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['reason' => 'motivo', 'proposed_start' => 'data proposta', 'notes' => 'note']);

        $squadre = $this->squadreDellUtente($request);

        $richiesta = DB::transaction(function () use ($request, $id, $data, $squadre) {
            $ordine = $this->soloDelCommittente(WorkOrder::query(), $squadre)
                ->whereNotIn('status', ['draft', 'cancelled', 'completed'])
                ->lockForUpdate()
                ->findOrFail($id);

            $aperta = RescheduleRequest::query()
                ->where('work_order_id', $ordine->id)
                ->where('team_id', $ordine->team_id)
                ->where('status', 'aperta')
                ->exists();
            if ($aperta) {
                throw ValidationException::withMessages([
                    'reason' => 'C\'è già una richiesta in attesa di risposta per questo ordine.',
                ]);
            }

            return RescheduleRequest::create([
                'tenant_id' => $ordine->tenant_id,
                'work_order_id' => $ordine->id,
                'team_id' => $ordine->team_id,
                'requested_by' => $request->user()->id,
                'reason' => $data['reason'],
                'proposed_start' => $data['proposed_start'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'aperta',
            ]);
        });

        Audit::log('impresa.riprogrammazione_chiesta', $richiesta, [
            'work_order_id' => $richiesta->work_order_id,
            'reason' => $richiesta->reason,
        ]);

        return response()->json(['data' => $richiesta], 201);
    }

    /** Le squadre esterne e attive di cui l'utente fa parte oggi. */
    private function squadreDellUtente(Request $request)
    {
        return \App\Models\Team::query()
            ->where('is_external', true)
            ->where('is_active', true)
            ->whereIn('id', DB::table('team_members')
                ->where('user_id', $request->user()->id)
                ->whereNull('left_on')
                ->pluck('team_id'))
            ->get(['id', 'name', 'client_id']);
    }

    /**
     * Solo gli ordini del committente della squadra: un ordine rimasto
     * assegnato a un'impresa dopo un cambio di committente non si espone
     * dal portale. Le squadre d'epoca senza committente vedono come prima.
     */
    private function soloDelCommittente($query, $squadre)
    {
        return $query->where(function ($q) use ($squadre) {
            // Base impossibile: senza squadre non si apre niente
            $q->whereRaw('1 = 0');
            foreach ($squadre as $squadra) {
                $q->orWhere(function ($qq) use ($squadra) {
                    $qq->where('team_id', $squadra->id);
                    if ($squadra->client_id !== null) {
                        $qq->where('client_id', $squadra->client_id);
                    }
                });
            }
        });
    }
}
