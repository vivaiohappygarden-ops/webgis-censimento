<?php

namespace App\Services\Works;

use App\Models\PriceListItem;
use App\Models\WorkOrder;

/**
 * Riepilogo economico dei consuntivi di un ordine: ore e quantità totali
 * e, se l'ordine ha listino e lavorazione, la valorizzazione della
 * quantità nell'unità di misura della voce di listino corrispondente.
 * Usato dal dettaglio ordine e dalla rendicontazione: un solo calcolo.
 */
class WorkOrderEconomics
{
    /**
     * @param  WorkOrder  $workOrder  con la relazione logs già caricata
     * @return array{total_man_hours: float, quantities: \Illuminate\Support\Collection, valued: ?array}
     */
    public function consuntivo(WorkOrder $workOrder): array
    {
        $logs = $workOrder->logs;
        $quantities = $logs->whereNotNull('quantity')
            ->groupBy(fn ($log) => $log->unit ?? '')
            ->map(fn ($group) => round((float) $group->sum('quantity'), 2));

        $valued = null;
        if ($workOrder->price_list_id !== null && $workOrder->work_type_id !== null) {
            $items = PriceListItem::query()
                ->where('price_list_id', $workOrder->price_list_id)
                ->where('work_type_id', $workOrder->work_type_id)
                ->get();
            if ($items->count() > 1) {
                // Più fasce di prezzo (codici articolo diversi): scegliere in
                // silenzio darebbe un importo che può essere semplicemente sbagliato
                $valued = [
                    'ambiguous' => true,
                    'reason' => 'Il listino ha più voci per questa lavorazione: l\'importo non è univoco.',
                ];
            } elseif ($items->count() === 1) {
                $item = $items->first();
                // Si valorizza solo la quantità espressa nella stessa unità
                // della voce di listino: mai moltiplicare mele per prezzo delle pere
                $quantity = $quantities[$item->unit] ?? null;
                $baseAmount = $quantity !== null ? round($quantity * (float) $item->unit_price, 2) : null;
                $amount = $baseAmount;
                if ($amount !== null) {
                    // Spese generali e costi della sicurezza della voce, se presenti,
                    // entrano nell'importo: campi accettati = campi conteggiati
                    if ($item->overhead_pct !== null) {
                        $amount = round($amount * (1 + (float) $item->overhead_pct / 100), 2);
                    }
                    if ($item->safety_cost !== null) {
                        $amount = round($amount + (float) $item->safety_cost, 2);
                    }
                }
                $valued = [
                    'unit' => $item->unit,
                    'unit_price' => (float) $item->unit_price,
                    'quantity' => $quantity,
                    'base_amount' => $baseAmount,
                    'overhead_pct' => $item->overhead_pct !== null ? (float) $item->overhead_pct : null,
                    'safety_cost' => $item->safety_cost !== null ? (float) $item->safety_cost : null,
                    'amount' => $amount,
                ];
            }
        }

        return [
            'total_man_hours' => round((float) $logs->sum('man_hours'), 2),
            'quantities' => $quantities,
            'valued' => $valued,
        ];
    }
}
