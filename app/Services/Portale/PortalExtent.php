<?php

namespace App\Services\Portale;

use App\Models\Client;
use Illuminate\Support\Facades\DB;

/**
 * Rettangolo che contiene tutto il patrimonio pubblicabile di un committente.
 *
 * Serve sia alla mappa a schermo intero sia all'anteprima nella home, che
 * devono inquadrare esattamente lo stesso territorio.
 */
class PortalExtent
{
    public static function per(Client $client): ?array
    {
        $query = PortalQuery::assets($client)->select('assets.geom');

        $riga = DB::selectOne(
            'SELECT ST_XMin(e) AS x1, ST_YMin(e) AS y1, ST_XMax(e) AS x2, ST_YMax(e) AS y2
             FROM (SELECT ST_Extent(geom::geometry) AS e FROM ('.$query->toSql().') AS pubblici) AS r',
            $query->getBindings(),
        );

        if ($riga?->x1 === null) {
            return null;
        }

        return [
            'sw' => [(float) $riga->x1, (float) $riga->y1],
            'ne' => [(float) $riga->x2, (float) $riga->y2],
        ];
    }

    /** Sfondi cartografici configurati e utilizzabili. */
    public static function sfondi(): array
    {
        return collect(config('portal.basemaps', []))
            ->filter(fn ($s) => ! empty($s['url']))
            ->values()->all();
    }
}
