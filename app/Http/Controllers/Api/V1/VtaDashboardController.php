<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/** Scadenzario VTA: ultima valutazione per albero, scadute e in scadenza. */
class VtaDashboardController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:assets.view')];
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        // Ultima valutazione per ogni albero (DISTINCT ON). Il join su trees
        // esclude gli abbattuti: un albero rimosso non ha ricontrolli da
        // programmare ne' classi di rischio da esporre
        $latest = collect(DB::select(<<<'SQL'
            SELECT latest.tree_id, latest.assessed_on, latest.failure_class,
                   latest.outcome, latest.next_check_due, a.census_code
            FROM (
              SELECT DISTINCT ON (ta.tree_id)
                     ta.tree_id, ta.assessed_on, ta.failure_class, ta.outcome, ta.next_check_due
              FROM tree_assessments ta
              WHERE ta.tenant_id = ? AND ta.deleted_at IS NULL
              ORDER BY ta.tree_id, ta.assessed_on DESC, ta.created_at DESC
            ) latest
            JOIN assets a ON a.id = latest.tree_id AND a.deleted_at IS NULL
            JOIN trees t ON t.asset_id = latest.tree_id AND t.removed_on IS NULL
            SQL, [$tenantId]));

        $today = now()->toDateString();
        $soon = now()->addDays(30)->toDateString();

        $overdue = $latest->filter(fn ($r) => $r->next_check_due !== null && $r->next_check_due < $today)
            ->sortBy('next_check_due')->values();
        $upcoming = $latest->filter(fn ($r) => $r->next_check_due !== null && $r->next_check_due >= $today && $r->next_check_due <= $soon)
            ->sortBy('next_check_due')->values();

        $byClass = $latest->groupBy(fn ($r) => $r->failure_class ?? 'n.d.')
            ->map->count()
            ->sortKeys();

        // Il join esclude gli alberi il cui asset e' uscito dal censimento
        $treesTotal = Tree::query()
            ->join('assets', 'assets.id', '=', 'trees.asset_id')
            ->whereNull('assets.deleted_at')
            ->whereNull('trees.removed_on')->count();

        return response()->json([
            'data' => [
                'trees_total' => $treesTotal,
                'assessed' => $latest->count(),
                'never_assessed' => max(0, $treesTotal - $latest->count()),
                'overdue_count' => $overdue->count(),
                'upcoming_count' => $upcoming->count(),
                'by_class' => $byClass,
                'overdue' => $overdue->take(100)->values(),
                'upcoming' => $upcoming->take(100)->values(),
            ],
        ]);
    }

    /** Alberi monumentali, soggetti a tutela o dedicati (L. 10/2013). */
    public function tutelati(): JsonResponse
    {
        $trees = Tree::query()
            ->join('assets', 'assets.id', '=', 'trees.asset_id')
            ->whereNull('assets.deleted_at')
            ->where(fn ($w) => $w
                ->where('trees.is_monumental', true)
                ->orWhere('trees.is_protected', true)
                ->orWhere('trees.is_dedicated', true))
            ->select([
                'trees.asset_id', 'trees.genus', 'trees.species', 'trees.common_name',
                'trees.is_monumental', 'trees.monumental_ref',
                'trees.is_protected', 'trees.protection_ref',
                'trees.is_dedicated', 'trees.dedicated_to',
                'trees.removed_on',
                'assets.census_code',
            ])
            ->orderBy('assets.census_code')
            ->limit(500)
            ->get();

        return response()->json(['data' => $trees]);
    }
}
