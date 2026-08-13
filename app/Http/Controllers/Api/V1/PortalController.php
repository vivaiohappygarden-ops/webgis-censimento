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

        $client = \App\Models\Client::query()->find($user->client_id);
        $areaIds = Area::query()
            ->whereHas('locality.site', fn ($q) => $q->where('client_id', $user->client_id))
            ->pluck('id');

        $areas = Area::query()
            ->with('locality:id,name,site_id', 'locality.site:id,name')
            ->whereIn('id', $areaIds)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'code', 'locality_id', 'computed_area_sqm']);

        $orders = WorkOrder::query()
            ->with('area:id,name')
            ->where('status', 'completed')
            ->where(function ($w) use ($areaIds) {
                $w->whereIn('area_id', $areaIds)
                    ->orWhereIn('id', WorkOrderAsset::query()
                        ->join('assets', 'assets.id', '=', 'work_order_assets.asset_id')
                        ->whereIn('assets.area_id', $areaIds)
                        ->select('work_order_assets.work_order_id'));
            })
            ->orderByDesc('completed_at')
            ->limit(20)
            ->get(['id', 'code', 'title', 'status', 'completed_at', 'area_id']);

        $issues = Issue::query()
            ->with('area:id,name')
            ->where(function ($w) use ($areaIds) {
                $w->whereIn('area_id', $areaIds)
                    ->orWhereIn('asset_id', Asset::query()->whereIn('area_id', $areaIds)->select('id'));
            })
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
                    ->where(function ($w) use ($areaIds) {
                        $w->whereIn('area_id', $areaIds)
                            ->orWhereIn('id', WorkOrderAsset::query()
                                ->join('assets', 'assets.id', '=', 'work_order_assets.asset_id')
                                ->whereIn('assets.area_id', $areaIds)
                                ->select('work_order_assets.work_order_id'));
                    })->count(),
                'open_issues' => Issue::query()
                    ->whereIn('status', ['open', 'in_charge'])
                    ->where(function ($w) use ($areaIds) {
                        $w->whereIn('area_id', $areaIds)
                            ->orWhereIn('asset_id', Asset::query()->whereIn('area_id', $areaIds)->select('id'));
                    })->count(),
            ],
            'areas' => $areas->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'code' => $a->code,
                'locality' => $a->locality?->name,
                'site' => $a->locality?->site?->name,
                'area_sqm' => $a->computed_area_sqm !== null ? (float) $a->computed_area_sqm : null,
            ]),
            'orders' => $orders->map(fn ($o) => [
                'code' => $o->code,
                'title' => $o->title,
                'completed_at' => $o->completed_at?->toIso8601String(),
                'area' => $o->area?->name,
            ]),
            'issues' => $issues->map(fn ($i) => [
                'code' => $i->code,
                'status' => $i->status,
                'severity' => $i->severity,
                'description' => $i->description,
                'area' => $i->area?->name,
                'created_at' => $i->created_at?->toIso8601String(),
                'resolved_at' => $i->resolved_at?->toIso8601String(),
            ]),
        ]);
    }
}
