<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class TileController extends Controller
{
    /** Vector tile MVT degli asset del tenant (layer "assets"). */
    public function assets(Request $request, int $z, int $x, int $y): Response
    {
        abort_unless($z >= 0 && $z <= 22, 404);
        $max = 2 ** $z;
        abort_unless($x >= 0 && $x < $max && $y >= 0 && $y < $max, 404);

        $tenantId = $request->user()->tenant_id;

        $filters = '';
        $bindings = [$z, $x, $y, $tenantId];
        if ($request->filled('area_id')) {
            $filters .= ' AND a.area_id = ?';
            $bindings[] = (string) $request->string('area_id');
        }
        if ($request->filled('object_type_id')) {
            $filters .= ' AND a.object_type_id = ?';
            $bindings[] = (string) $request->string('object_type_id');
        }

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
                t.allowed_geometry
              FROM assets a
              JOIN catalog_object_types t ON t.id = a.object_type_id
              CROSS JOIN bounds
              WHERE a.tenant_id = ?
                AND a.deleted_at IS NULL
                AND a.geom && ST_Transform(ST_Expand(bounds.b, 4096), 4326)
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
}
