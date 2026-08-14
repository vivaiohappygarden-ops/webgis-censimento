<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Issue;
use App\Models\PhytoTreatment;
use App\Models\Tree;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Statistiche: numeri d'insieme su censimento, VTA, lavori, segnalazioni
 * e trattamenti. Solo aggregati già presenti nei dati: il dettaglio resta
 * nelle pagine dedicate.
 */
class StatsController extends Controller implements HasMiddleware
{
    private const TIMEZONE = 'Europe/Rome';

    public static function middleware(): array
    {
        return [new Middleware('can:works.view')];
    }

    public function overview(Request $request): JsonResponse
    {
        $canAssets = $request->user()->can('assets.view');

        return response()->json(['data' => [
            // Le sezioni su censimento e VTA seguono il permesso della
            // pagina corrispondente: chi non le può aprire non le vede qui
            'census' => $canAssets ? $this->census() : null,
            'vta' => $canAssets ? $this->vta($request->user()->tenant_id) : null,
            'works' => $this->works(),
            'issues' => $this->issues(),
            'phyto' => $this->phyto(),
        ]]);
    }

    /** Censimento: consistenze per tipologia e per specie. */
    private function census(): array
    {
        $byType = Asset::query()
            ->join('catalog_object_types as t', 't.id', '=', 'assets.object_type_id')
            ->groupBy('t.name')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(10)
            ->selectRaw('t.name AS label, COUNT(*) AS n')
            ->pluck('n', 'label');

        // Il campo species porta già il binomio completo (es. "Tilia
        // cordata"); il genere fa da ripiego quando manca
        $speciesLabel = "COALESCE(NULLIF(TRIM(trees.species), ''), NULLIF(TRIM(trees.genus), ''), 'Specie non indicata')";
        $bySpecies = Tree::query()
            ->join('assets', 'assets.id', '=', 'trees.asset_id')
            ->whereNull('assets.deleted_at')
            ->whereNull('trees.removed_on')
            ->groupByRaw($speciesLabel)
            ->orderByRaw('COUNT(*) DESC')
            ->limit(10)
            ->selectRaw("{$speciesLabel} AS label, COUNT(*) AS n")
            ->pluck('n', 'label')
            ->map(fn ($n) => (int) $n);

        $protectedCount = Tree::query()
            ->join('assets', 'assets.id', '=', 'trees.asset_id')
            ->whereNull('assets.deleted_at')
            ->whereNull('trees.removed_on')
            ->where(fn ($w) => $w->where('trees.is_monumental', true)
                ->orWhere('trees.is_protected', true)
                ->orWhere('trees.is_dedicated', true))
            ->count();

        return [
            'assets_total' => Asset::query()->count(),
            'areas_total' => Area::query()->count(),
            'trees_total' => Tree::query()->whereNull('removed_on')->count(),
            'protected_trees' => $protectedCount,
            'by_type' => $byType,
            'by_species' => $bySpecies,
        ];
    }

    /** VTA: classi dell'ultima valutazione e valutazioni per anno. */
    private function vta(string $tenantId): array
    {
        $byClass = collect(DB::select(<<<'SQL'
            SELECT COALESCE(latest.failure_class, 'n.d.') AS label, COUNT(*) AS n
            FROM (
              SELECT DISTINCT ON (ta.tree_id) ta.tree_id, ta.failure_class
              FROM tree_assessments ta
              WHERE ta.tenant_id = ? AND ta.deleted_at IS NULL
              ORDER BY ta.tree_id, ta.assessed_on DESC, ta.created_at DESC
            ) latest
            JOIN assets a ON a.id = latest.tree_id AND a.deleted_at IS NULL
            GROUP BY 1 ORDER BY 1
            SQL, [$tenantId]))->pluck('n', 'label')->map(fn ($n) => (int) $n);

        $firstYear = now(self::TIMEZONE)->year - 4;
        $byYear = collect(DB::select(<<<'SQL'
            SELECT EXTRACT(YEAR FROM assessed_on)::int AS y, COUNT(*) AS n
            FROM tree_assessments
            WHERE tenant_id = ? AND deleted_at IS NULL AND EXTRACT(YEAR FROM assessed_on) >= ?
            GROUP BY 1 ORDER BY 1
            SQL, [$tenantId, $firstYear]))->pluck('n', 'y');

        return [
            'by_class' => $byClass,
            'per_year' => collect(range($firstYear, now(self::TIMEZONE)->year))
                ->mapWithKeys(fn ($y) => [(string) $y => (int) ($byYear[$y] ?? 0)]),
        ];
    }

    /** Lavori: consistenza per stato e completati mese per mese. */
    private function works(): array
    {
        $byStatus = WorkOrder::query()
            ->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status');

        return [
            'by_status' => $byStatus,
            'completed_by_month' => $this->monthlySeries(
                WorkOrder::query()->whereNotNull('completed_at'), 'completed_at',
            ),
        ];
    }

    /** Segnalazioni: aperte e risolte mese per mese, tempo medio di risoluzione. */
    private function issues(): array
    {
        $avgDays = DB::selectOne(
            'SELECT AVG(EXTRACT(EPOCH FROM (resolved_at - created_at)) / 86400.0) AS d
             FROM issues WHERE tenant_id = ? AND deleted_at IS NULL AND resolved_at IS NOT NULL',
            [auth()->user()->tenant_id],
        )->d;

        return [
            'open_now' => Issue::query()->whereIn('status', ['open', 'in_charge'])->count(),
            'opened_by_month' => $this->monthlySeries(Issue::query(), 'created_at'),
            'resolved_by_month' => $this->monthlySeries(
                Issue::query()->whereNotNull('resolved_at'), 'resolved_at',
            ),
            'avg_resolution_days' => $avgDays !== null ? round((float) $avgDays, 1) : null,
        ];
    }

    /** Trattamenti fitosanitari: per anno e per prodotto. */
    private function phyto(): array
    {
        $firstYear = now(self::TIMEZONE)->year - 4;
        $byYear = PhytoTreatment::query()
            ->whereRaw('EXTRACT(YEAR FROM treated_on) >= ?', [$firstYear])
            ->groupByRaw('EXTRACT(YEAR FROM treated_on)')
            ->selectRaw('EXTRACT(YEAR FROM treated_on)::int AS y, COUNT(*) AS n')
            ->pluck('n', 'y');

        return [
            'per_year' => collect(range($firstYear, now(self::TIMEZONE)->year))
                ->mapWithKeys(fn ($y) => [(string) $y => (int) ($byYear[$y] ?? 0)]),
            'by_product' => PhytoTreatment::query()
                ->groupBy('product_name')->orderByRaw('COUNT(*) DESC')->limit(8)
                ->selectRaw('product_name AS label, COUNT(*) AS n')->pluck('n', 'label'),
        ];
    }

    /**
     * Serie degli ultimi 12 mesi (chiave AAAA-MM in fuso italiano), mesi
     * vuoti compresi: i grafici hanno bisogno dell'asse completo.
     *
     * @return array<string, int>
     */
    private function monthlySeries($query, string $column): array
    {
        $start = now(self::TIMEZONE)->startOfMonth()->subMonths(11);

        $counts = $query
            ->where($column, '>=', $start->copy()->timezone('UTC'))
            ->groupByRaw("to_char({$column} AT TIME ZONE 'Europe/Rome', 'YYYY-MM')")
            ->selectRaw("to_char({$column} AT TIME ZONE 'Europe/Rome', 'YYYY-MM') AS m, COUNT(*) AS n")
            ->pluck('n', 'm');

        $series = [];
        foreach (range(0, 11) as $i) {
            $key = $start->copy()->addMonths($i)->format('Y-m');
            $series[$key] = (int) ($counts[$key] ?? 0);
        }

        return $series;
    }
}
