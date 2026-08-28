<?php

namespace App\Services\Territorio;

use App\Models\Locality;
use Illuminate\Support\Facades\DB;

/**
 * I dati geometrici per le planimetrie della scheda località.
 *
 * Tutto esce in EPSG:3857 (la proiezione dei riquadri cartografici): così il
 * disegno si sovrappone allo sfondo senza scarti. Le distanze vere si
 * ottengono moltiplicando per il coseno della latitudine (fattore di scala
 * di Mercatore), lo stesso conto che fa la mappa a video per le chiome.
 */
class PlanimetriaDati
{
    /**
     * @return array{
     *   aree: list<array<string, mixed>>,
     *   perimetro: list<list<array{0: float, 1: float}>>|null,
     *   lat: float
     * }|null null quando la località non ha aree disegnate
     */
    public static function perLocalita(Locality $localita): ?array
    {
        $aree = DB::select(<<<'SQL'
            SELECT ar.id, ar.name, ar.code, ar.status,
                   ST_AsGeoJSON(ST_Transform(ar.geom, 3857), 2) AS g,
                   ST_AsGeoJSON(ST_Transform(ST_PointOnSurface(ar.geom), 3857), 2) AS centro,
                   ST_Y(ST_Transform(ST_PointOnSurface(ar.geom), 4326)) AS lat,
                   ST_Area(ar.geom::geography) AS mq
            FROM areas ar
            WHERE ar.locality_id = ? AND ar.deleted_at IS NULL
            ORDER BY ar.name, ar.created_at
            SQL, [$localita->id]);

        if ($aree === []) {
            return null;
        }

        $risultato = [];
        foreach ($aree as $i => $area) {
            $elementi = DB::select(<<<'SQL'
                SELECT a.id, a.census_code,
                       GeometryType(a.geom) AS gtype,
                       ST_AsGeoJSON(ST_Transform(a.geom, 3857), 2) AS g,
                       -- Il punto "dentro" la figura: e' l'ancora giusta per
                       -- l'etichetta anche su superfici concave e linee
                       ST_AsGeoJSON(ST_Transform(ST_PointOnSurface(a.geom), 3857), 2) AS ancora,
                       (tr.asset_id IS NOT NULL) AS albero,
                       tr.crown_diameter_m
                FROM assets a
                LEFT JOIN trees tr ON tr.asset_id = a.id
                WHERE a.area_id = ? AND a.deleted_at IS NULL AND a.status <> 'removed'
                ORDER BY a.census_code NULLS LAST, a.created_at
                SQL, [$area->id]);

            $risultato[] = [
                'numero' => $i + 1,
                'nome' => $area->name,
                'codice' => $area->code,
                'stato' => $area->status,
                'mq' => round((float) $area->mq, 2),
                'anelli' => self::anelli(json_decode($area->g, true)),
                'centro' => json_decode($area->centro, true)['coordinates'],
                'lat' => (float) $area->lat,
                'elementi' => array_map(fn ($e) => [
                    'id' => $e->id,
                    'etichetta' => $e->census_code,
                    'tipo' => $e->gtype,
                    'geo' => json_decode($e->g, true),
                    'ancora' => json_decode($e->ancora, true)['coordinates'],
                    'albero' => (bool) $e->albero,
                    'chioma_m' => $e->crown_diameter_m !== null ? (float) $e->crown_diameter_m : null,
                ], $elementi),
            ];
        }

        $perimetro = DB::selectOne(
            'SELECT ST_AsGeoJSON(ST_Transform(geom, 3857), 2) AS g FROM localities WHERE id = ? AND geom IS NOT NULL',
            [$localita->id],
        );

        return [
            'aree' => $risultato,
            'perimetro' => $perimetro ? self::anelli(json_decode($perimetro->g, true)) : null,
            'lat' => (float) $aree[0]->lat,
        ];
    }

    /**
     * Gli anelli esterni di un poligono o multipoligono GeoJSON (i buchi non
     * si campiscono: per una planimetria d'insieme il contorno basta).
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    public static function anelli(array $geo): array
    {
        return match ($geo['type'] ?? '') {
            'Polygon' => [$geo['coordinates'][0]],
            'MultiPolygon' => array_map(fn ($p) => $p[0], $geo['coordinates']),
            default => [],
        };
    }
}
