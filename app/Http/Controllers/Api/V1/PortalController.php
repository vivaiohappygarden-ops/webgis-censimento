<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Issue;
use App\Models\WorkOrder;
use App\Models\WorkOrderAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Portale cliente in sola lettura: ogni utente agganciato a un cliente
 * (users.client_id) vede esclusivamente il proprio territorio — aree,
 * elementi, lavori completati e segnalazioni. Nessun dato economico.
 */
class PortalController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:portal.view')];
    }

    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->client_id === null) {
            return response()->json([
                'linked' => false,
                'message' => 'Questo utente non è ancora collegato a un cliente: chiedere all\'amministratore.',
            ]);
        }

        // Rapporto cessato (cliente eliminato o di un altro tenant): il
        // portale si chiude, non mostra dati orfani
        $client = \App\Models\Client::query()->find($user->client_id);
        if ($client === null) {
            return response()->json([
                'linked' => false,
                'message' => 'Il collegamento al cliente non è più attivo: chiedere all\'amministratore.',
            ]);
        }

        $areaIds = Area::query()
            ->whereHas('locality.site', fn ($q) => $q->where('client_id', $user->client_id))
            ->pluck('id');

        // Un ordine appartiene al portale solo se coerente col cliente:
        // intestato a lui, oppure su una sua area, oppure senza area ma con
        // elementi suoi. Un ordine "misto" (area di un altro cliente) resta
        // fuori: mai rivelare lavori o aree altrui.
        $orderScope = function ($w) use ($areaIds, $user) {
            $w->where('client_id', $user->client_id)
                ->orWhereIn('area_id', $areaIds)
                ->orWhere(fn ($q) => $q->whereNull('area_id')
                    ->whereIn('id', WorkOrderAsset::query()
                        ->join('assets', 'assets.id', '=', 'work_order_assets.asset_id')
                        ->whereIn('assets.area_id', $areaIds)
                        ->select('work_order_assets.work_order_id')));
        };
        $issueScope = function ($w) use ($areaIds) {
            $w->whereIn('area_id', $areaIds)
                ->orWhere(fn ($q) => $q->whereNull('area_id')
                    ->whereIn('asset_id', Asset::query()->whereIn('area_id', $areaIds)->select('id')));
        };

        $areas = Area::query()
            ->with('locality:id,name,site_id', 'locality.site:id,name')
            ->whereIn('id', $areaIds)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'code', 'locality_id', 'computed_area_sqm']);

        $orders = WorkOrder::query()
            ->with('area:id,name')
            ->where('status', 'completed')
            ->where($orderScope)
            ->orderByDesc('completed_at')
            ->limit(20)
            ->get(['id', 'code', 'title', 'status', 'completed_at', 'area_id']);

        $issues = Issue::query()
            ->with('area:id,name')
            ->where($issueScope)
            ->orderByRaw("CASE WHEN status IN ('resolved','dismissed') THEN 1 ELSE 0 END")
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'code', 'status', 'severity', 'description', 'area_id', 'created_at', 'resolved_at']);

        return response()->json([
            'linked' => true,
            'client' => ['name' => $client?->name],
            'counts' => [
                'areas' => $areaIds->count(),
                'assets' => Asset::query()->whereIn('area_id', $areaIds)->count(),
                'completed_orders' => WorkOrder::query()
                    ->where('status', 'completed')
                    ->where($orderScope)->count(),
                'open_issues' => Issue::query()
                    ->whereIn('status', ['open', 'in_charge'])
                    ->where($issueScope)->count(),
            ],
            'areas' => $areas->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'code' => $a->code,
                'locality' => $a->locality?->name,
                'site' => $a->locality?->site?->name,
                'area_sqm' => $a->computed_area_sqm !== null ? (float) $a->computed_area_sqm : null,
            ]),
            // Il nome dell'area compare solo se è davvero un'area del
            // cliente: mai nomi di aree altrui
            'orders' => $orders->map(fn ($o) => [
                'code' => $o->code,
                'title' => $o->title,
                'completed_at' => $o->completed_at?->toIso8601String(),
                'area' => $areaIds->contains($o->area_id) ? $o->area?->name : null,
            ]),
            'issues' => $issues->map(fn ($i) => [
                'code' => $i->code,
                'status' => $i->status,
                'severity' => $i->severity,
                'description' => $i->description,
                'area' => $areaIds->contains($i->area_id) ? $i->area?->name : null,
                'created_at' => $i->created_at?->toIso8601String(),
                'resolved_at' => $i->resolved_at?->toIso8601String(),
            ]),
        ]);
    }
}
