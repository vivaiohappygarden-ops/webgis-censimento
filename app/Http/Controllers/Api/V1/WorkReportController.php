<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Services\Works\WorkOrderEconomics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Rendicontazione dei lavori completati: riepilogo per cliente e periodo
 * con ore, quantità e importi da listino, pronto per la fatturazione.
 */
class WorkReportController extends Controller implements HasMiddleware
{
    /**
     * Il rendiconto ragiona in giorni italiani: i confini del periodo e la
     * data di completamento mostrata devono usare lo stesso fuso, o un
     * lavoro chiuso a mezzanotte finirebbe nel mese sbagliato.
     */
    private const TIMEZONE = 'Europe/Rome';

    public static function middleware(): array
    {
        return [new Middleware('can:works.view')];
    }

    public function lavori(Request $request, WorkOrderEconomics $economics): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'client_id' => ['nullable', 'uuid'],
        ], [
            'from.required' => 'Indicare la data iniziale del periodo.',
            'to.required' => 'Indicare la data finale del periodo.',
            'to.after_or_equal' => 'La data finale deve essere uguale o successiva a quella iniziale.',
        ]);

        $orders = WorkOrder::query()
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [
                // ->utc() prima del binding: il driver serializza l'orario senza
                // offset e Postgres lo leggerebbe nel fuso di sessione (UTC)
                \Illuminate\Support\Carbon::parse($data['from'], self::TIMEZONE)->startOfDay()->utc(),
                \Illuminate\Support\Carbon::parse($data['to'], self::TIMEZONE)->endOfDay()->utc(),
            ])
            ->when(! empty($data['client_id']), fn ($q) => $q->where('client_id', $data['client_id']))
            ->with([
                'client:id,name', 'area:id,name', 'team:id,name',
                'workType:id,code,name,unit', 'priceList:id,code,name',
                'logs',
            ])
            ->orderBy('completed_at')
            ->get();

        // Voci di listino per tutte le coppie listino+lavorazione in UNA query
        $itemsByPair = $economics->itemsForOrders($orders);

        $rows = $orders->map(function (WorkOrder $order) use ($economics, $itemsByPair) {
            $consuntivo = $economics->consuntivo($order, $itemsByPair);
            $valued = $consuntivo['valued'];

            // Stato della valorizzazione, dichiarato: un rendiconto che tace
            // il motivo di un importo mancante non è un rendiconto
            $valueState = match (true) {
                $order->price_list_id === null => 'no_list',
                $order->work_type_id === null => 'no_work_type',
                $valued === null => 'no_item',
                ($valued['ambiguous'] ?? false) === true => 'ambiguous',
                $valued['amount'] === null => 'no_quantity',
                default => 'ok',
            };

            return [
                'id' => $order->id,
                'code' => $order->code,
                'title' => $order->title,
                'client' => $order->client?->only(['id', 'name']),
                'area' => $order->area?->name,
                'work_type' => $order->workType?->name,
                'team' => $order->team?->name,
                'price_list' => $order->priceList?->code,
                'completed_at' => $order->completed_at?->toIso8601String(),
                // Data già formattata nel fuso del rendiconto: tabella e CSV
                // la usano così com'è, senza reinterpretarla nel fuso del browser
                'completed_on' => $order->completed_at?->timezone(self::TIMEZONE)->format('d/m/Y'),
                'man_hours' => round((float) $order->logs->sum('man_hours'), 2),
                'quantities' => $consuntivo['quantities'],
                'amount' => $valueState === 'ok' ? $valued['amount'] : null,
                'value_state' => $valueState,
            ];
        });

        $byClient = $rows->groupBy(fn ($row) => $row['client']['id'] ?? '')->map(fn ($group) => [
            'client' => $group->first()['client'],
            'orders' => $group->values(),
            'subtotal_amount' => round((float) $group->sum(fn ($r) => $r['amount'] ?? 0), 2),
            'subtotal_hours' => round((float) $group->sum('man_hours'), 2),
            'unvalued_count' => $group->where('value_state', '!=', 'ok')->count(),
        ])->values();

        return response()->json([
            'from' => $data['from'],
            'to' => $data['to'],
            'clients' => $byClient,
            'orders_count' => $rows->count(),
            'total_amount' => round((float) $rows->sum(fn ($r) => $r['amount'] ?? 0), 2),
            'total_hours' => round((float) $rows->sum('man_hours'), 2),
            'unvalued_count' => $rows->where('value_state', '!=', 'ok')->count(),
        ]);
    }
}
