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

/**
 * Le richieste di riprogrammazione delle imprese, viste dal gestionale.
 *
 * Accettare una richiesta con data proposta sposta l'inizio previsto
 * dell'ordine a quella data e trascina la fine della stessa distanza (la
 * durata non cambia); la decisione resta agli atti con chi e quando.
 */
class RiprogrammazioneController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:works.manage')];
    }

    public function index(Request $request): JsonResponse
    {
        $stato = $request->query('stato', 'aperta');

        $richieste = RescheduleRequest::query()
            ->when(in_array($stato, RescheduleRequest::STATI, true), fn ($q) => $q->where('status', $stato))
            ->with([
                'workOrder:id,code,title,status,planned_start,planned_end',
                'team:id,name',
                'requester:id,name',
            ])
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $richieste->map(fn ($r) => [
                'id' => $r->id,
                'reason' => $r->reason,
                'motivo' => RescheduleRequest::MOTIVI[$r->reason] ?? $r->reason,
                'proposed_start' => $r->proposed_start?->toDateString(),
                'notes' => $r->notes,
                'status' => $r->status,
                'response_note' => $r->response_note,
                'created_at' => $r->created_at?->toDateTimeString(),
                'impresa' => $r->team?->name,
                'richiedente' => $r->requester?->name,
                'ordine' => $r->workOrder ? [
                    'id' => $r->workOrder->id,
                    'code' => $r->workOrder->code,
                    'title' => $r->workOrder->title,
                    'status' => $r->workOrder->status,
                    'planned_start' => $r->workOrder->planned_start?->toDateString(),
                    'planned_end' => $r->workOrder->planned_end?->toDateString(),
                ] : null,
            ])->values(),
        ]);
    }

    public function decidi(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'esito' => ['required', Rule::in(['accettata', 'rifiutata'])],
            'response_note' => ['nullable', 'string', 'max:1000'],
        ], [], ['esito' => 'esito', 'response_note' => 'risposta']);

        $richiesta = DB::transaction(function () use ($request, $id, $data) {
            $richiesta = RescheduleRequest::query()->lockForUpdate()->findOrFail($id);
            if ($richiesta->status !== 'aperta') {
                abort(409, 'La richiesta è già stata decisa.');
            }

            if ($data['esito'] === 'accettata') {
                $ordine = WorkOrder::query()->lockForUpdate()->findOrFail($richiesta->work_order_id);
                // Gli stati terminali sono immutabili anche da qui: la
                // storia di un ordine completato o annullato non si riscrive
                if (in_array($ordine->status, ['completed', 'cancelled'], true)) {
                    abort(409, 'L\'ordine è stato nel frattempo chiuso: la richiesta si può solo non accogliere.');
                }
                if ($richiesta->proposed_start !== null) {
                    // La fine si trascina della stessa distanza: la durata
                    // concordata non cambia per uno slittamento
                    if ($ordine->planned_start !== null && $ordine->planned_end !== null) {
                        $giorni = $ordine->planned_start->diffInDays($ordine->planned_end, false);
                        $ordine->planned_end = $richiesta->proposed_start->copy()->addDays($giorni);
                    }
                    $ordine->planned_start = $richiesta->proposed_start;
                    // Un ordine nato con la sola data di fine non deve uscire
                    // col periodo capovolto: la fine non precede mai l'inizio
                    if ($ordine->planned_end !== null && $ordine->planned_end->lt($ordine->planned_start)) {
                        $ordine->planned_end = $ordine->planned_start->copy();
                    }
                    $ordine->version += 1;
                    $ordine->updated_by = $request->user()->id;
                    $ordine->save();
                }
            }

            $richiesta->forceFill([
                'status' => $data['esito'],
                'response_note' => $data['response_note'] ?? null,
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
            ])->save();

            return $richiesta;
        });

        Audit::log('impresa.riprogrammazione_decisa', $richiesta, [
            'esito' => $richiesta->status,
            'work_order_id' => $richiesta->work_order_id,
        ]);

        return response()->json(['data' => $richiesta->fresh()]);
    }
}
