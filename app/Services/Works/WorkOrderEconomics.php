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
    /** Chiave della coppia listino+lavorazione nelle lookup precaricate. */
    public static function pairKey(string $priceListId, string $workTypeId): string
    {
        return $priceListId.'|'.$workTypeId;
    }

    /**
     * Voci di listino per tutte le coppie (listino, lavorazione) di un insieme
     * di ordini, in UNA query: il rendiconto non deve fare una query per ordine.
     *
     * @param  \Illuminate\Support\Collection<int, WorkOrder>  $orders
     * @return \Illuminate\Support\Collection raggruppata per pairKey
     */
    public function itemsForOrders($orders)
    {
        $pairs = $orders
            ->filter(fn ($o) => $o->price_list_id !== null && $o->work_type_id !== null);
        if ($pairs->isEmpty()) {
            return collect();
        }

        return PriceListItem::query()
            ->whereIn('price_list_id', $pairs->pluck('price_list_id')->unique())
            ->whereIn('work_type_id', $pairs->pluck('work_type_id')->unique())
            ->get()
            ->groupBy(fn ($item) => self::pairKey($item->price_list_id, $item->work_type_id));
    }

    /**
     * @param  WorkOrder  $workOrder  con la relazione logs già caricata
     * @param  \Illuminate\Support\Collection|null  $itemsByPair  lookup di itemsForOrders (facoltativa)
     * @return array{total_man_hours: float, quantities: \Illuminate\Support\Collection, valued: ?array}
     */
    public function consuntivo(WorkOrder $workOrder, $itemsByPair = null): array
    {
        $logs = $workOrder->logs;
        $quantities = $logs->whereNotNull('quantity')
            ->groupBy(fn ($log) => $log->unit ?? '')
            ->map(fn ($group) => round((float) $group->sum('quantity'), 2));

        return [
            'total_man_hours' => round((float) $logs->sum('man_hours'), 2),
            'quantities' => $quantities,
            'valued' => $this->valorizza($workOrder, $quantities, $itemsByPair),
        ];
    }

    /**
     * Il previsto dell'ordine: la somma delle quantità previste degli
     * elementi collegati, per unità di misura, valorizzata con le stesse
     * regole del consuntivo. È l'altra gamba del confronto previsto/eseguito.
     *
     * @param  WorkOrder  $workOrder  con la relazione assets già caricata
     * @return array{quantities: \Illuminate\Support\Collection, valued: ?array}
     */
    public function previsto(WorkOrder $workOrder, $itemsByPair = null): array
    {
        $quantities = $workOrder->assets->whereNotNull('planned_quantity')
            ->groupBy(fn ($row) => $row->unit ?? '')
            ->map(fn ($group) => round((float) $group->sum('planned_quantity'), 2));

        return [
            'quantities' => $quantities,
            'valued' => $this->valorizza($workOrder, $quantities, $itemsByPair),
        ];
    }

    /**
     * Valorizzazione di un insieme di quantità (per unità) con la voce di
     * listino della lavorazione dell'ordine. Unica per consuntivo e previsto:
     * due calcoli separati prima o poi darebbero due importi diversi.
     */
    private function valorizza(WorkOrder $workOrder, $quantities, $itemsByPair = null): ?array
    {
        $valued = null;
        if ($workOrder->price_list_id !== null && $workOrder->work_type_id !== null) {
            $items = $itemsByPair !== null
                ? ($itemsByPair[self::pairKey($workOrder->price_list_id, $workOrder->work_type_id)] ?? collect())
                : PriceListItem::query()
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

        return $valued;
    }
}
