<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlantingSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/**
 * Bilancio arboreo tra due date (L. 10/2013): consistenza iniziale e finale,
 * nuovi impianti, abbattimenti, variazione e dettaglio per specie.
 *
 * Un albero "esiste" dalla data di impianto (planted_on) o, se non nota,
 * dalla data di censimento; cessa alla data di abbattimento (removed_on).
 */
class TreeBalanceController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:assets.view')];
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $tenantId = $request->user()->tenant_id;
        $from = $data['from'];
        $to = $data['to'];

        $totals = DB::selectOne(<<<'SQL'
            WITH t AS (
              SELECT COALESCE(tr.planted_on, a.created_at::date) AS starts_on,
                     tr.removed_on
              FROM trees tr
              JOIN assets a ON a.id = tr.asset_id
              WHERE tr.tenant_id = ? AND a.deleted_at IS NULL
            )
            SELECT
              COUNT(*) FILTER (WHERE starts_on < ?::date
                                 AND (removed_on IS NULL OR removed_on >= ?::date)) AS initial_count,
              COUNT(*) FILTER (WHERE starts_on BETWEEN ?::date AND ?::date)          AS planted_count,
              COUNT(*) FILTER (WHERE removed_on BETWEEN ?::date AND ?::date)         AS felled_count,
              COUNT(*) FILTER (WHERE starts_on <= ?::date
                                 AND (removed_on IS NULL OR removed_on > ?::date))   AS final_count
            FROM t
            SQL, [$tenantId, $from, $from, $from, $to, $from, $to, $to, $to]);

        $bySpecies = DB::select(<<<'SQL'
            WITH t AS (
              SELECT COALESCE(tr.planted_on, a.created_at::date) AS starts_on,
                     tr.removed_on,
                     -- species contiene il binomio completo (convenzione CAM); genus è il ripiego
                     COALESCE(NULLIF(TRIM(tr.species), ''), NULLIF(TRIM(tr.genus), ''), 'Specie non indicata') AS species_label
              FROM trees tr
              JOIN assets a ON a.id = tr.asset_id
              WHERE tr.tenant_id = ? AND a.deleted_at IS NULL
            )
            SELECT species_label,
                   COUNT(*) FILTER (WHERE starts_on BETWEEN ?::date AND ?::date)  AS planted,
                   COUNT(*) FILTER (WHERE removed_on BETWEEN ?::date AND ?::date) AS felled
            FROM t
            GROUP BY species_label
            HAVING COUNT(*) FILTER (WHERE starts_on BETWEEN ?::date AND ?::date)
                 + COUNT(*) FILTER (WHERE removed_on BETWEEN ?::date AND ?::date) > 0
            ORDER BY 2 + 3 DESC, species_label
            LIMIT 50
            SQL, [$tenantId, $from, $to, $from, $to, $from, $to, $from, $to]);

        // Stato attuale dei posti liberi (non storicizzato per data)
        $sites = PlantingSite::query()
            ->join('assets', 'assets.id', '=', 'planting_sites.asset_id')
            ->whereNull('assets.deleted_at')
            ->selectRaw('planting_sites.status, COUNT(*) AS n')
            ->groupBy('planting_sites.status')
            ->pluck('n', 'status');

        $replacements = PlantingSite::query()
            ->join('assets', 'assets.id', '=', 'planting_sites.asset_id')
            ->whereNull('assets.deleted_at')
            ->where('planting_sites.origin', 'felling')
            ->where('planting_sites.status', 'planted')
            ->count();

        return response()->json([
            'data' => [
                'from' => $from,
                'to' => $to,
                'initial_count' => (int) $totals->initial_count,
                'planted_count' => (int) $totals->planted_count,
                'felled_count' => (int) $totals->felled_count,
                'final_count' => (int) $totals->final_count,
                'variation' => (int) $totals->final_count - (int) $totals->initial_count,
                'by_species' => $bySpecies,
                'planting_sites' => [
                    'free' => (int) ($sites['free'] ?? 0),
                    'reserved' => (int) ($sites['reserved'] ?? 0),
                    'planted' => (int) ($sites['planted'] ?? 0),
                    'unusable' => (int) ($sites['unusable'] ?? 0),
                    'replacements_from_felling' => $replacements,
                ],
            ],
        ]);
    }
}
