<?php

namespace App\Services\Export;

use App\Models\Area;
use App\Models\Asset;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/**
 * Export conforme al "Modello dati per il censimento del verde urbano" v2.1:
 * un layer per macro-categoria/geometria (P1, L1, S1, ... S3, S4) con i
 * tracciati record delle Master shapefile. GeoJSON sempre disponibile;
 * shapefile zippato in RDN2008 tramite ogr2ogr (gdal-bin) quando installato.
 */
class CamExporter
{
    public const LAYERS = ['P1', 'L1', 'S1', 'P2', 'L2', 'S2', 'P3', 'L3', 'S3', 'S4'];

    public function featureCollection(string $layer, int $tenantMetricSrid): array
    {
        $features = $layer === 'S3'
            ? $this->areaFeatures()
            : $this->assetFeatures($layer);

        return [
            'type' => 'FeatureCollection',
            'name' => $layer,
            'crs' => ['type' => 'name', 'properties' => ['name' => 'urn:ogc:def:crs:OGC:1.3:CRS84']],
            'metadata' => [
                'standard' => 'Modello dati censimento verde urbano v2.1 (CAM DM 63/2020)',
                'layer' => $layer,
                'target_srid_shapefile' => $tenantMetricSrid,
                'exported_at' => now()->toIso8601String(),
            ],
            'features' => $features,
        ];
    }

    /** Converte la FeatureCollection in shapefile zippato (RDN2008) via ogr2ogr. */
    public function toShapefileZip(array $featureCollection, string $layer, int $targetSrid): string
    {
        if (! $this->ogr2ogrAvailable()) {
            throw ValidationException::withMessages([
                'format' => "Export shapefile non disponibile: sul server manca GDAL (ogr2ogr). Installa il pacchetto 'gdal-bin' oppure usa il formato geojson.",
            ]);
        }

        $dir = sys_get_temp_dir().'/cam-export-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $jsonPath = "{$dir}/{$layer}.geojson";
        $shpPath = "{$dir}/{$layer}.shp";
        $zipPath = "{$dir}/{$layer}.zip";

        file_put_contents($jsonPath, json_encode($featureCollection));

        $process = new Process([
            'ogr2ogr', '-f', 'ESRI Shapefile',
            '-t_srs', "EPSG:{$targetSrid}",
            '-lco', 'ENCODING=UTF-8',
            $shpPath, $jsonPath,
        ]);
        $process->setTimeout(120)->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('ogr2ogr fallito: '.substr($process->getErrorOutput(), 0, 500));
        }

        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        foreach (glob("{$dir}/{$layer}.{shp,shx,dbf,prj,cpg}", GLOB_BRACE) as $file) {
            $zip->addFile($file, basename($file));
        }
        $zip->close();

        return $zipPath;
    }

    public function ogr2ogrAvailable(): bool
    {
        $process = new Process(['ogr2ogr', '--version']);
        $process->setTimeout(10);

        try {
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function assetFeatures(string $layer): array
    {
        $rows = Asset::query()
            ->join('catalog_object_types AS t', 't.id', '=', 'assets.object_type_id')
            ->join('areas', 'areas.id', '=', 'assets.area_id')
            ->join('localities', 'localities.id', '=', 'areas.locality_id')
            ->join('sites', 'sites.id', '=', 'localities.site_id')
            ->leftJoin('trees', 'trees.asset_id', '=', 'assets.id')
            ->leftJoin('users AS editor', 'editor.id', '=', 'assets.updated_by')
            ->where('t.cam_layer', $layer)
            ->whereNull('assets.deleted_at')
            ->orderBy('assets.census_code')
            ->selectRaw(<<<'SQL'
                ST_AsGeoJSON(assets.geom)::json AS geometry,
                sites.istat_code, localities.code AS zona, localities.survey_zone_code,
                areas.code AS area_code,
                assets.census_code, assets.valid_from, assets.valid_to, assets.updated_at,
                assets.notes, assets.status,
                t.code AS codice, substring(t.code, 2, 1) AS tp, substring(t.code, 3, 2) AS ts,
                COALESCE(editor.username, editor.name) AS modif_da,
                trees.plant_number, trees.genus, trees.species, trees.cultivar,
                trees.height_m, trees.dbh_cm, trees.crown_diameter_m,
                (SELECT p.original_filename FROM photos p
                  WHERE p.asset_id = assets.id AND p.deleted_at IS NULL
                  ORDER BY p.created_at LIMIT 1) AS foto
                SQL)
            ->get();

        return $rows->map(function ($row, $i) use ($layer) {
            $props = [
                'ID_ZRIL' => $row->survey_zone_code,
                'CODE_ISTAT' => $row->istat_code,
                'ZONA' => $row->zona,
                'AREA' => $row->area_code,
                'OBJ_ID' => $row->census_code ?? (string) ($i + 1),
                'TP' => $row->tp,
                'TS' => $row->ts,
                'CODICE' => $row->codice,
                'DATA_INI' => $this->camDate($row->valid_from),
                'DATA_FINE' => $this->camDate($row->valid_to),
                'DATA_AGG' => $this->camDate($row->updated_at),
                'MODIF_DA' => $row->modif_da,
                'NOTE' => $row->notes,
                'FOTO' => $row->foto,
            ];

            // Campi specifici Master P1/L1/S1 (vegetazione)
            if (in_array($layer, ['P1', 'L1', 'S1'], true)) {
                $props += [
                    'PT' => $row->plant_number ?? $row->census_code,
                    'GENERE' => $row->genus,
                    'SPECIE' => $row->species,
                    'VARIETA' => $row->cultivar,
                    'STATO' => $this->camStato($row->status),
                ];
            }
            if ($layer === 'P1') {
                $props += [
                    'H_m' => $row->height_m !== null ? (float) $row->height_m : null,
                    'DIAM_TRONC' => $row->dbh_cm !== null ? (float) $row->dbh_cm : null,
                    'DIAM_CHIOM' => $row->crown_diameter_m !== null ? (float) $row->crown_diameter_m : null,
                ];
            }

            return [
                'type' => 'Feature',
                'geometry' => is_string($row->geometry) ? json_decode($row->geometry, true) : $row->geometry,
                'properties' => $props,
            ];
        })->values()->all();
    }

    /** Layer S3: perimetri delle aree di gestione (Master shapefile S3). */
    private function areaFeatures(): array
    {
        $rows = Area::query()
            ->join('localities', 'localities.id', '=', 'areas.locality_id')
            ->join('sites', 'sites.id', '=', 'localities.site_id')
            ->leftJoin('users AS editor', 'editor.id', '=', 'areas.updated_by')
            ->whereNull('areas.deleted_at')
            ->orderBy('areas.code')
            ->selectRaw(<<<'SQL'
                ST_AsGeoJSON(areas.geom)::json AS geometry,
                sites.istat_code, localities.code AS zona, localities.survey_zone_code,
                areas.code AS area_code, areas.name AS nome_area, areas.manager,
                areas.street_code, areas.valid_from, areas.valid_to, areas.updated_at,
                areas.notes, areas.computed_area_sqm, areas.computed_perimeter_m,
                COALESCE(editor.username, editor.name) AS modif_da
                SQL)
            ->get();

        return $rows->map(function ($row, $i) {
            return [
                'type' => 'Feature',
                'geometry' => is_string($row->geometry) ? json_decode($row->geometry, true) : $row->geometry,
                'properties' => [
                    'ID_ZRIL' => $row->survey_zone_code,
                    'CODE_ISTAT' => $row->istat_code,
                    'ZONA' => $row->zona,
                    'AREA' => $row->area_code,
                    'OBJ_ID' => $row->area_code ?? (string) ($i + 1),
                    'TP' => '3',
                    'TS' => '25',
                    'CODICE' => 'S325500',
                    'DATA_INI' => $this->camDate($row->valid_from),
                    'DATA_FINE' => $this->camDate($row->valid_to),
                    'DATA_AGG' => $this->camDate($row->updated_at),
                    'MODIF_DA' => $row->modif_da,
                    'NOTE' => $row->notes,
                    'FOTO' => null,
                    'NOME_AREA' => $row->nome_area,
                    'CODE_VIA' => $row->street_code,
                    'GESTORE' => $row->manager,
                    'AREA_mq' => (float) $row->computed_area_sqm,
                    'PERIM_m' => (float) $row->computed_perimeter_m,
                ],
            ];
        })->values()->all();
    }

    /** Data nel formato GGMMAAAA richiesto dai tracciati record MD. */
    private function camDate($date): ?string
    {
        return $date ? \Illuminate\Support\Carbon::parse($date)->format('dmY') : null;
    }

    private function camStato(?string $status): string
    {
        return match ($status) {
            'active' => 'Pianta viva',
            'dead' => 'Pianta morta',
            'stump' => 'Ceppaia',
            'removed', 'felled' => 'Abbattuta',
            'dismissed' => 'Rimossa',
            default => $status ?? 'n.d.',
        };
    }
}
