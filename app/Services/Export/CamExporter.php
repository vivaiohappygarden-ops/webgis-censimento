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
        // Il layer S3 (fruizione e gestione) porta sia gli elementi censiti
        // (aree gioco, aree cani, ...) sia i perimetri delle aree di gestione
        $features = $layer === 'S3'
            ? [...$this->assetFeatures($layer, $tenantMetricSrid), ...$this->areaFeatures($tenantMetricSrid)]
            : $this->assetFeatures($layer, $tenantMetricSrid);

        // PROG: progressivo di consegna per layer, generato dal writer
        foreach ($features as $i => $feature) {
            $features[$i]['properties']['PROG'] = $i + 1;
        }

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

    private function assetFeatures(string $layer, int $srid): array
    {
        $rows = Asset::query()
            ->join('catalog_object_types AS t', 't.id', '=', 'assets.object_type_id')
            ->join('areas', 'areas.id', '=', 'assets.area_id')
            ->join('localities', 'localities.id', '=', 'areas.locality_id')
            ->join('sites', 'sites.id', '=', 'localities.site_id')
            ->leftJoin('trees', 'trees.asset_id', '=', 'assets.id')
            ->leftJoin('users AS editor', 'editor.id', '=', 'assets.updated_by')
            ->where('t.cam_layer', $layer)
            // I limiti delle aree di gestione escono da areaFeatures: un
            // elemento censito con quel codice duplicherebbe il CODICE nella
            // consegna e sparirebbe al re-import (che li salta)
            ->where('t.code', '!=', 'S325500')
            ->whereNull('assets.deleted_at')
            ->orderBy('assets.census_code')
            // Le misure sono ricalcolate nel CRS di consegna del tenant
            // (GIS-DATA-MODEL §6.3.1), non copiate dalle colonne generate
            // che sono fisse sul fuso Ovest EPSG:7791
            ->selectRaw(<<<'SQL'
                ST_AsGeoJSON(assets.geom)::json AS geometry,
                sites.istat_code, localities.code AS zona, localities.survey_zone_code,
                areas.code AS area_code,
                assets.object_type_id,
                assets.census_code, assets.valid_from, assets.valid_to, assets.updated_at,
                assets.notes, assets.status, assets.surveyed_at, assets.attributes,
                round(ST_Area(ST_Transform(assets.geom, (?)::int))::numeric, 2) AS export_area_sqm,
                round(ST_Length(ST_Transform(assets.geom, (?)::int))::numeric, 2) AS export_length_m,
                round(ST_Perimeter(ST_Transform(assets.geom, (?)::int))::numeric, 2) AS export_perimeter_m,
                t.code AS codice, substring(t.code, 2, 1) AS tp, substring(t.code, 3, 2) AS ts,
                COALESCE(editor.username, editor.name) AS modif_da,
                trees.plant_number, trees.genus, trees.species, trees.cultivar,
                trees.height_m, trees.dbh_cm, trees.crown_diameter_m,
                (SELECT p.original_filename FROM photos p
                  WHERE p.asset_id = assets.id AND p.deleted_at IS NULL
                  ORDER BY p.created_at LIMIT 1) AS foto
                SQL, [$srid, $srid, $srid])
            ->get();

        // Mapping dichiarato nel Catalog Manager: un campo personalizzato con
        // cam_field alimenta il campo del tracciato senza cablarlo qui
        // (GIS-DATA-MODEL §6.3.4); vince sul mapping standard
        $camFields = \App\Models\CustomField::query()
            ->whereNotNull('cam_field')
            ->get()
            ->groupBy('object_type_id');

        return $rows->map(function ($row, $i) use ($layer, $camFields) {
            // Il cast del modello può aver già trasformato il JSON in array
            $attrs = is_array($row->attributes)
                ? $row->attributes
                : (json_decode((string) $row->attributes, true) ?: []);

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
                'DATA_RIL' => $this->camDate($row->surveyed_at),
                'MODIF_DA' => $row->modif_da,
                'NOTE' => $row->notes,
                'FOTO' => $row->foto,
            ];

            // Campi vegetazione (P1/L1/S1): per gli alberi dalla scheda albero,
            // per siepi e superfici vegetali dagli attributi del tipo
            if (in_array($layer, ['P1', 'L1', 'S1'], true)) {
                $props += [
                    'GENERE' => $row->genus ?? $this->text($attrs, 'genere'),
                    'SPECIE' => $row->species ?? $this->text($attrs, 'specie'),
                    'VARIETA' => $row->cultivar ?? $this->text($attrs, 'varieta'),
                    'STATO' => $this->camStato($row->status),
                ];
            }
            if ($layer === 'P1') {
                $props += [
                    'PT' => $row->plant_number ?? $row->census_code,
                    'H_m' => $row->height_m !== null ? (float) $row->height_m : null,
                    'DIAM_TRONC' => $row->dbh_cm !== null ? (float) $row->dbh_cm : null,
                    'DIAM_CHIOM' => $row->crown_diameter_m !== null ? (float) $row->crown_diameter_m : null,
                ];
            }
            if ($layer === 'L1') {
                $props['H_m'] = $this->number($attrs, 'altezza_m');
            }
            // Elementi lineari: larghezza dichiarata e lunghezza calcolata
            if (in_array($layer, ['L1', 'L2', 'L3'], true)) {
                $props += [
                    'LARG_m' => $this->number($attrs, 'larghezza_m'),
                    'LUNG_m' => $row->export_length_m !== null ? (float) $row->export_length_m : null,
                ];
            }
            // Superfici: misure ricalcolate nel CRS di consegna
            if (in_array($layer, ['S1', 'S2', 'S3', 'S4'], true)) {
                $props += [
                    'AREA_mq' => $row->export_area_sqm !== null ? (float) $row->export_area_sqm : null,
                    'PERIM_m' => $row->export_perimeter_m !== null ? (float) $row->export_perimeter_m : null,
                ];
            }

            foreach ($camFields->get($row->object_type_id, collect()) as $definition) {
                $value = in_array($definition->field_type, ['number', 'integer'], true)
                    ? $this->number($attrs, $definition->key)
                    : $this->text($attrs, $definition->key);
                if ($value !== null) {
                    $props[$definition->cam_field] = $value;
                }
            }

            return [
                'type' => 'Feature',
                'geometry' => is_string($row->geometry) ? json_decode($row->geometry, true) : $row->geometry,
                'properties' => $props,
            ];
        })->values()->all();
    }

    private function text(array $attrs, string $key): ?string
    {
        $value = trim((string) ($attrs[$key] ?? ''));

        return $value !== '' ? $value : null;
    }

    private function number(array $attrs, string $key): ?float
    {
        $value = str_replace(',', '.', trim((string) ($attrs[$key] ?? '')));

        return is_numeric($value) ? (float) $value : null;
    }

    /** Layer S3: perimetri delle aree di gestione (Master shapefile S3). */
    private function areaFeatures(int $srid): array
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
                areas.notes,
                round(ST_Area(ST_Transform(areas.geom, (?)::int))::numeric, 2) AS export_area_sqm,
                round(ST_Perimeter(ST_Transform(areas.geom, (?)::int))::numeric, 2) AS export_perimeter_m,
                COALESCE(editor.username, editor.name) AS modif_da
                SQL, [$srid, $srid])
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
                    'AREA_mq' => (float) $row->export_area_sqm,
                    'PERIM_m' => (float) $row->export_perimeter_m,
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
