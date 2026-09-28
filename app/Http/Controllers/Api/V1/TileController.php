<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ListQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class TileController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:assets.view')];
    }

    /** Vector tile MVT degli asset del tenant (layer "assets"). */
    public function assets(Request $request, int $z, int $x, int $y): Response
    {
        abort_unless($z >= 0 && $z <= 22, 404);
        $max = 2 ** $z;
        abort_unless($x >= 0 && $x < $max && $y >= 0 && $y < $max, 404);

        $tenantId = $request->user()->tenant_id;
        [$filters, $filtri] = $this->filtri($request);
        $bindings = [$z, $x, $y, $tenantId, ...$filtri];

        $sql = <<<SQL
            WITH bounds AS (SELECT ST_TileEnvelope(?, ?, ?) AS b),
            mvtgeom AS (
              SELECT
                ST_AsMVTGeom(ST_Transform(a.geom, 3857), bounds.b, 4096, 256, true) AS geom,
                a.id::text AS id,
                a.census_code,
                a.status,
                t.code AS type_code,
                t.name AS type_name,
                t.allowed_geometry,
                -- Diametro della chioma: serve alla mappa per disegnare il
                -- cerchio a dimensione reale sopra il punto dell'albero
                tr.crown_diameter_m AS chioma_m,
                -- Un lavoro in corso o programmato sull'elemento: la mappa
                -- lo puo' evidenziare con il livello "lavori aperti"
                EXISTS (
                  SELECT 1 FROM work_order_assets woa
                  JOIN work_orders wo ON wo.id = woa.work_order_id
                  WHERE woa.asset_id = a.id AND wo.deleted_at IS NULL
                    AND wo.status IN ('planned', 'assigned', 'in_progress', 'suspended')
                ) AS lavoro_aperto
              FROM assets a
              JOIN catalog_object_types t ON t.id = a.object_type_id
              LEFT JOIN trees tr ON tr.asset_id = a.id
              CROSS JOIN bounds
              WHERE a.tenant_id = ?
                AND a.deleted_at IS NULL
                AND a.geom && ST_Transform(
                      ST_Expand(bounds.b, (ST_XMax(bounds.b) - ST_XMin(bounds.b)) * 256.0 / 4096.0), 4326)
                {$filters}
            )
            SELECT ST_AsMVT(mvtgeom.*, 'assets', 4096, 'geom') AS tile FROM mvtgeom
        SQL;

        $row = DB::selectOne($sql, $bindings);
        $tile = $row->tile ?? null;

        if (is_resource($tile)) {
            $tile = stream_get_contents($tile);
        }

        if ($tile === null || $tile === '') {
            return response('', 204);
        }

        return response($tile, 200, [
            'Content-Type' => 'application/vnd.mapbox-vector-tile',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    /**
     * I filtri della mappa (area, tipo, committente, archivio), scritti una
     * volta sola per le tessere e per il conteggio dei livelli: le due
     * risposte devono parlare dello stesso insieme.
     *
     * @return array{0: string, 1: array<int, string>} frammento SQL e valori
     */
    private function filtri(Request $request): array
    {
        ListQuery::validateUuidFilters($request, ['area_id', 'object_type_id', 'client_id']);

        $filters = '';
        $bindings = [];
        if ($request->filled('area_id')) {
            $filters .= ' AND a.area_id = ?';
            $bindings[] = (string) $request->string('area_id');
        }
        if ($request->filled('object_type_id')) {
            $filters .= ' AND a.object_type_id = ?';
            $bindings[] = (string) $request->string('object_type_id');
        }
        // Committente: aree -> località -> sedi -> cliente
        if ($request->filled('client_id')) {
            $filters .= ' AND a.area_id IN (
                SELECT ar.id FROM areas ar
                JOIN localities l ON l.id = ar.locality_id
                JOIN sites s ON s.id = l.site_id
                WHERE s.client_id = ? AND ar.tenant_id = a.tenant_id)';
            $bindings[] = (string) $request->string('client_id');
        }
        // L'archivio (abbattuti e dismessi) non sta sulla mappa di tutti i
        // giorni: archivio=0 lo nasconde, archivio=1 mostra solo quello.
        // Stessi parametri e stessa semantica dell'elenco e del CSV;
        // hide_removed resta col vecchio significato per compatibilità
        if ($request->has('archivio')) {
            $filters .= $request->boolean('archivio')
                ? ' AND a.status IN ('.\App\Support\AssetStatus::sqlArchivio().')'
                : ' AND a.status NOT IN ('.\App\Support\AssetStatus::sqlArchivio().')';
        } elseif ($request->boolean('hide_removed')) {
            $filters .= " AND a.status <> 'removed'";
        }


        return [$filters, $bindings];
    }

    /**
     * Quanti elementi ci sono per livello (tipo principale e sottotipo del
     * Modello Dati), con gli stessi filtri delle tessere: il pannello dei
     * livelli elenca solo quello che c'e' davvero, con il suo numero, e
     * conta a parte gli elementi con lavori aperti.
     */
    public function livelli(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        [$filters, $filtri] = $this->filtri($request);
        $righe = DB::select(<<<SQL
            SELECT substr(t.code, 2, 1) AS tp, substr(t.code, 3, 2) AS ts, t.allowed_geometry AS geo,
                   count(*) AS n,
                   count(*) FILTER (WHERE EXISTS (
                       SELECT 1 FROM work_order_assets woa
                       JOIN work_orders wo ON wo.id = woa.work_order_id
                       WHERE woa.asset_id = a.id AND wo.deleted_at IS NULL
                         AND wo.status IN ('planned', 'assigned', 'in_progress', 'suspended'))) AS lavori_aperti
            FROM assets a
            JOIN catalog_object_types t ON t.id = a.object_type_id
            WHERE a.tenant_id = ? AND a.deleted_at IS NULL {$filters}
            GROUP BY 1, 2, 3
            ORDER BY 1, 2, 3
            SQL, [$tenantId, ...$filtri]);

        return response()->json(['data' => array_map(fn ($r) => [
            'tp' => $r->tp, 'ts' => $r->ts, 'geo' => $r->geo, 'n' => (int) $r->n, 'lavori_aperti' => (int) $r->lavori_aperti,
        ], $righe)]);
    }
}
