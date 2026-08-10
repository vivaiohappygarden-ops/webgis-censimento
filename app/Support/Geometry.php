<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Geometry
{
    public const GEOJSON_TYPES = [
        'Point', 'MultiPoint', 'LineString', 'MultiLineString', 'Polygon', 'MultiPolygon',
    ];

    /**
     * Converte una geometria GeoJSON (già validata come struttura) in EWKB
     * esadecimale pronto per l'inserimento nella colonna geometry.
     * Il passaggio dal database garantisce parsing e validazione reali:
     * geometrie invalide, vuote o con coordinate fuori dal range lon/lat
     * vengono rifiutate come errore di validazione (mai 500).
     *
     * @throws ValidationException
     */
    public static function toEwkb(array $geojson, bool $forceMultiPolygon = false): string
    {
        $inner = $forceMultiPolygon
            ? 'ST_Multi(ST_Force2D(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)))'
            : 'ST_Force2D(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))';

        try {
            $row = DB::selectOne(<<<SQL
                WITH g AS (SELECT {$inner} AS geom)
                SELECT geom::text AS ewkb,
                       ST_IsValid(geom) AS valid,
                       ST_IsEmpty(geom) AS is_empty,
                       ST_XMin(geom) AS xmin, ST_XMax(geom) AS xmax,
                       ST_YMin(geom) AS ymin, ST_YMax(geom) AS ymax
                FROM g
                SQL, [json_encode($geojson)]);
        } catch (\Illuminate\Database\QueryException) {
            throw ValidationException::withMessages([
                'geometry' => 'La geometria GeoJSON non è valida.',
            ]);
        }

        if ($row->is_empty) {
            throw ValidationException::withMessages([
                'geometry' => 'La geometria è vuota: servono coordinate reali.',
            ]);
        }

        if (! $row->valid) {
            throw ValidationException::withMessages([
                'geometry' => 'La geometria non è topologicamente valida (auto-intersezioni o anelli errati).',
            ]);
        }

        if ($row->xmin < -180 || $row->xmax > 180 || $row->ymin < -90 || $row->ymax > 90) {
            throw ValidationException::withMessages([
                'geometry' => 'Coordinate fuori dal range WGS84 (longitudine ±180, latitudine ±90): il GeoJSON deve essere in EPSG:4326.',
            ]);
        }

        return $row->ewkb;
    }

    /** Regole di validazione Laravel per un campo geometria GeoJSON. */
    public static function rules(string $field = 'geometry'): array
    {
        return [
            $field => ['required', 'array'],
            "{$field}.type" => ['required', 'string', 'in:'.implode(',', self::GEOJSON_TYPES)],
            "{$field}.coordinates" => ['required', 'array', 'min:1'],
        ];
    }
}
