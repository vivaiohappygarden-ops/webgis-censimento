<?php

namespace App\Support;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Il perimetro di chi esegue soltanto i lavori affidati (ruolo "esecutore",
 * dal 27/09/2026): gli ordini visibili dal campo per lui (assegnati a lui o
 * a una sua squadra, negli stati di campo), gli elementi collegati a quegli
 * ordini e le aree di quegli elementi e di quegli ordini. Lo usano la
 * sincronizzazione dell'app di campo e il caricamento delle fotografie:
 * la regola e' una sola, o il telefono e il server direbbero due cose.
 */
final class Esecuzione
{
    /** @return array{ordini: Collection<int, string>, elementi: Collection<int, string>, aree: Collection<int, string>} */
    public static function perimetro(User $user): array
    {
        $ordini = WorkOrder::query()->visibleInField($user)->pluck('work_orders.id');
        if ($ordini->isEmpty()) {
            return ['ordini' => collect(), 'elementi' => collect(), 'aree' => collect()];
        }

        $elementi = DB::table('work_order_assets')
            ->whereIn('work_order_id', $ordini)
            ->pluck('asset_id')->unique()->values();

        $aree = DB::table('assets')->whereIn('id', $elementi)->whereNotNull('area_id')->pluck('area_id')
            ->merge(WorkOrder::query()->whereIn('work_orders.id', $ordini)->whereNotNull('area_id')->pluck('area_id'))
            ->unique()->values();

        return ['ordini' => $ordini, 'elementi' => $elementi, 'aree' => $aree];
    }

    /** L'elemento sta in un ordine di lavoro visibile dal campo per l'utente. */
    public static function elementoNeiLavoriDi(User $user, string $assetId): bool
    {
        return DB::table('work_order_assets')
            ->where('asset_id', $assetId)
            ->whereIn('work_order_id', WorkOrder::query()->visibleInField($user)->select('work_orders.id'))
            ->exists();
    }

    /** L'elemento sta proprio in quell'ordine, e l'ordine e' visibile dal campo per l'utente. */
    public static function elementoNelLavoroDi(User $user, string $ordineId, string $assetId): bool
    {
        return WorkOrder::query()->visibleInField($user)->whereKey($ordineId)->exists()
            && DB::table('work_order_assets')->where('work_order_id', $ordineId)->where('asset_id', $assetId)->exists();
    }
}
