<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Tree;
use App\Support\RicercaTestuale;
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
        $clientId = $this->clientId($request);

        // Ultima valutazione per ogni albero (DISTINCT ON). Il join su trees
        // esclude gli abbattuti: un albero rimosso non ha ricontrolli da
        // programmare ne' classi di rischio da esporre
        $sql = <<<'SQL'
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
            SQL;
        $bindings = [$tenantId];

        if ($clientId !== null) {
            $sql .= "\n".<<<'SQL'
                JOIN areas ar ON ar.id = a.area_id
                JOIN localities lc ON lc.id = ar.locality_id
                JOIN sites s ON s.id = lc.site_id AND s.client_id = ?
                SQL;
            $bindings[] = $clientId;
        }

        $latest = collect(DB::select($sql, $bindings));

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
            ->whereNull('trees.removed_on')
            ->when($clientId !== null, fn ($q) => $q
                ->join('areas', 'areas.id', '=', 'assets.area_id')
                ->join('localities', 'localities.id', '=', 'areas.locality_id')
                ->join('sites', 'sites.id', '=', 'localities.site_id')
                ->where('sites.client_id', $clientId))
            ->count();

        return response()->json([
            'data' => [
                'trees_total' => $treesTotal,
                'assessed' => $latest->count(),
                'never_assessed' => max(0, $treesTotal - $latest->count()),
                'overdue_count' => $overdue->count(),
                'upcoming_count' => $upcoming->count(),
                'by_class' => $byClass,
            ],
        ]);
    }

    /**
     * Elenco degli alberi con l'ultima valutazione: e' la lista di lavoro
     * dello scadenzario. I riquadri e le classi del cruscotto sono solo
     * conteggi: da qui si vede quali alberi sono dietro ai numeri.
     */
    public function alberi(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'nullable', 'in:assessed,never,overdue,upcoming'],
            'class' => ['sometimes', 'nullable', 'in:A,B,C,C/D,D,nd'],
            'q' => ['sometimes', 'nullable', ...RicercaTestuale::regole()],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $tenantId = $request->user()->tenant_id;
        $today = now()->toDateString();
        $soon = now()->addDays(30)->toDateString();

        $ultima = DB::table('tree_assessments as ta')
            ->select(DB::raw('DISTINCT ON (ta.tree_id) ta.tree_id, ta.assessed_on, ta.failure_class, ta.outcome, ta.next_check_due'))
            ->where('ta.tenant_id', $tenantId)
            ->whereNull('ta.deleted_at')
            ->orderBy('ta.tree_id')->orderByDesc('ta.assessed_on')->orderByDesc('ta.created_at');

        $query = Asset::query()
            ->join('trees', 'trees.asset_id', '=', 'assets.id')
            ->whereNull('trees.removed_on')
            ->leftJoinSub($ultima, 'vta', 'vta.tree_id', '=', 'assets.id')
            ->leftJoin('areas', 'areas.id', '=', 'assets.area_id')
            ->leftJoin('localities', 'localities.id', '=', 'areas.locality_id')
            ->leftJoin('sites', 'sites.id', '=', 'localities.site_id')
            ->leftJoin('clients', 'clients.id', '=', 'sites.client_id')
            ->select([
                'assets.id', 'assets.census_code',
                'trees.genus', 'trees.species', 'trees.common_name',
                'areas.name as area_name', 'localities.name as locality_name',
                'clients.name as client_name',
                'vta.assessed_on', 'vta.failure_class', 'vta.outcome', 'vta.next_check_due',
            ])
            // Lo stato si calcola con le stesse date del cruscotto (orologio
            // dell'applicazione, non del database): i numeri devono tornare
            ->selectRaw(
                "CASE WHEN vta.tree_id IS NULL THEN 'mai_valutato'"
                .' WHEN vta.next_check_due < ? THEN \'scaduto\''
                .' WHEN vta.next_check_due <= ? THEN \'in_scadenza\''
                ." ELSE 'valutato' END AS stato",
                [$today, $soon],
            );

        $query->when($request->input('client_id'), fn ($q, $clientId) => $q->where('sites.client_id', $clientId));

        match ($request->input('status')) {
            'assessed' => $query->whereNotNull('vta.tree_id'),
            'never' => $query->whereNull('vta.tree_id'),
            'overdue' => $query->where('vta.next_check_due', '<', $today),
            'upcoming' => $query->where('vta.next_check_due', '>=', $today)->where('vta.next_check_due', '<=', $soon),
            default => null,
        };

        match ($request->input('class')) {
            // "n.d.": valutato ma senza classe assegnata
            'nd' => $query->whereNotNull('vta.tree_id')->whereNull('vta.failure_class'),
            null, '' => null,
            default => $query->where('vta.failure_class', $request->input('class')),
        };

        $query->cercaTesto($request->input('q'));

        // Prima le urgenze: scaduti (dal piu' vecchio), in scadenza, mai
        // valutati, e in coda i valutati con ricontrollo lontano o senza data
        $query->orderByRaw(
            'CASE WHEN vta.tree_id IS NULL THEN 2'
            .' WHEN vta.next_check_due < ? THEN 0'
            .' WHEN vta.next_check_due <= ? THEN 1'
            .' ELSE 3 END',
            [$today, $soon],
        )
            ->orderByRaw('vta.next_check_due ASC NULLS LAST')
            ->orderBy('assets.census_code')
            ->orderBy('assets.id');

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    /** Alberi monumentali, soggetti a tutela o dedicati (L. 10/2013). */
    public function tutelati(Request $request): JsonResponse
    {
        $clientId = $this->clientId($request);

        $trees = Tree::query()
            ->join('assets', 'assets.id', '=', 'trees.asset_id')
            ->whereNull('assets.deleted_at')
            ->when($clientId !== null, fn ($q) => $q
                ->join('areas', 'areas.id', '=', 'assets.area_id')
                ->join('localities', 'localities.id', '=', 'areas.locality_id')
                ->join('sites', 'sites.id', '=', 'localities.site_id')
                ->where('sites.client_id', $clientId))
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

    /** Filtro per committente comune alle sezioni del cruscotto. */
    private function clientId(Request $request): ?string
    {
        $request->validate(['client_id' => ['sometimes', 'nullable', 'uuid']]);

        return $request->input('client_id') ?: null;
    }
}
