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
     * Il passaggio dal database garantisce parsing e validazione reali.
     *
     * @throws ValidationException se il GeoJSON non è una geometria valida
     */
    public static function toEwkb(array $geojson, bool $forceMultiPolygon = false): string
    {
        $expr = $forceMultiPolygon
            ? 'ST_Multi(ST_Force2D(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)))'
            : 'ST_Force2D(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))';

        try {
            $row = DB::selectOne(
                "SELECT {$expr}::text AS g, ST_IsValid({$expr}) AS valid",
                [json_encode($geojson), json_encode($geojson)]
            );
        } catch (\Illuminate\Database\QueryException) {
            throw ValidationException::withMessages([
                'geometry' => 'La geometria GeoJSON non è valida.',
            ]);
        }

        if (! $row->valid) {
            throw ValidationException::withMessages([
                'geometry' => 'La geometria non è topologicamente valida (auto-intersezioni o anelli errati).',
            ]);
        }

        return $row->g;
    }

    /** Regole di validazione Laravel per un campo geometria GeoJSON. */
    public static function rules(string $field = 'geometry'): array
    {
        return [
            $field => ['required', 'array'],
            "{$field}.type" => ['required', 'string', 'in:'.implode(',', self::GEOJSON_TYPES)],
            "{$field}.coordinates" => ['required', 'array'],
        ];
    }
}
