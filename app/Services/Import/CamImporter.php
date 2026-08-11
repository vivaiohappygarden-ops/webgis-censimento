<?php

namespace App\Services\Import;

use App\Models\Area;
use App\Models\Asset;
use App\Models\CatalogObjectType;
use App\Models\Tree;
use App\Support\Geometry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/**
 * Import censimenti nel formato "Modello dati censimento verde urbano" v2.1:
 * shapefile zippato (RDN2008, riproiettato con ogr2ogr) oppure GeoJSON con i
 * campi delle Master shapefile (CODICE, OBJ_ID, GENERE, SPECIE, H_m, ...).
 *
 * Il percorso è l'inverso di CamExporter: CODICE risolve il tipo catalogo,
 * OBJ_ID diventa il codice censimento, i campi vegetazione popolano la
 * scheda albero. Dry-run obbligatorio prima dell'import transazionale.
 */
class CamImporter
{
    public const MAX_FEATURES = 5000;

    /** Stati CAM -> stati interni (inverso di CamExporter::camStato). */
    private const STATO_MAP = [
        'pianta viva' => 'active',
        'pianta morta' => 'dead',
        'ceppaia' => 'stump',
        'abbattuta' => 'removed',
        'rimossa' => 'dismissed',
    ];

    /** Converte l'upload (zip shapefile o GeoJSON) in FeatureCollection WGS84. */
    public function toGeoJson(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['json', 'geojson'], true)) {
            $decoded = json_decode($file->get(), true);
            if (! is_array($decoded)) {
                throw ValidationException::withMessages(['file' => 'Il file non è un JSON valido.']);
            }

            return $decoded;
        }

        if ($extension !== 'zip') {
            throw ValidationException::withMessages([
                'file' => 'Formato non riconosciuto: caricare uno shapefile zippato (.zip) o un GeoJSON.',
            ]);
        }

        if (! (new \App\Services\Export\CamExporter)->ogr2ogrAvailable()) {
            throw ValidationException::withMessages([
                'file' => "Import shapefile non disponibile: sul server manca GDAL (ogr2ogr). Usa il formato GeoJSON.",
            ]);
        }

        $dir = sys_get_temp_dir().'/cam-import-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        try {
            $zip = new \ZipArchive;
            if ($zip->open($file->getRealPath()) !== true) {
                throw ValidationException::withMessages(['file' => 'Archivio zip non leggibile.']);
            }

            $shpName = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                // Solo i componenti shapefile, senza percorsi (protezione zip-slip)
                $name = basename($zip->getNameIndex($i));
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (! in_array($ext, ['shp', 'shx', 'dbf', 'prj', 'cpg'], true)) {
                    continue;
                }
                file_put_contents("{$dir}/{$name}", $zip->getFromIndex($i));
                if ($ext === 'shp') {
                    $shpName = $name;
                }
            }
            $zip->close();

            if ($shpName === null) {
                throw ValidationException::withMessages(['file' => 'Nessun file .shp trovato nello zip.']);
            }
            if (! file_exists("{$dir}/".substr($shpName, 0, -4).'.prj')) {
                throw ValidationException::withMessages([
                    'file' => 'Manca il file .prj (sistema di coordinate): impossibile riproiettare con certezza.',
                ]);
            }

            $jsonPath = "{$dir}/import.geojson";
            $process = new Process([
                'ogr2ogr', '-f', 'GeoJSON',
                '-t_srs', 'EPSG:4326',
                $jsonPath, "{$dir}/{$shpName}",
            ]);
            $process->setTimeout(120)->run();

            if (! $process->isSuccessful() || ! file_exists($jsonPath)) {
                throw ValidationException::withMessages([
                    'file' => 'Conversione shapefile fallita: '.substr($process->getErrorOutput(), 0, 300),
                ]);
            }

            $decoded = json_decode(file_get_contents($jsonPath), true);
            if (! is_array($decoded)) {
                throw ValidationException::withMessages(['file' => 'Conversione shapefile non valida.']);
            }

            return $decoded;
        } finally {
            foreach (glob("{$dir}/*") ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function run(array $geojson, Area $area, bool $dryRun = true): array
    {
        if (($geojson['type'] ?? null) !== 'FeatureCollection' || ! is_array($geojson['features'] ?? null)) {
            throw ValidationException::withMessages(['file' => 'Il file deve essere una FeatureCollection GeoJSON.']);
        }

        $features = $geojson['features'];
        if (count($features) > self::MAX_FEATURES) {
            throw ValidationException::withMessages([
                'file' => 'Troppe feature ('.count($features).'): il limite per import è '.self::MAX_FEATURES.'.',
            ]);
        }

        $tenantId = $area->tenant_id;
        $typesByCode = CatalogObjectType::query()->get()->keyBy('code');

        $errors = [];
        $assetRows = [];
        $treeRows = [];
        $censusInFile = [];

        foreach ($features as $index => $feature) {
            $label = 'feature #'.($index + 1);
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $props = array_change_key_case($props, CASE_UPPER);

            $codice = trim((string) ($props['CODICE'] ?? ''));
            if ($codice === '' || ! $typesByCode->has($codice)) {
                $errors[] = ['index' => $index, 'error' => "{$label}: CODICE '{$codice}' assente o non presente nel catalogo."];

                continue;
            }
            $type = $typesByCode->get($codice);

            $geometry = $feature['geometry'] ?? null;
            if (! is_array($geometry) || empty($geometry['type'])) {
                $errors[] = ['index' => $index, 'error' => "{$label}: geometria mancante."];

                continue;
            }
            $allowed = array_map('strtoupper', $type->allowedGeometryTypes());
            if (! in_array(strtoupper($geometry['type']), $allowed, true)) {
                $errors[] = ['index' => $index, 'error' => "{$label}: geometria {$geometry['type']} non ammessa per {$type->code}."];

                continue;
            }

            try {
                $ewkb = Geometry::toEwkb($geometry);
            } catch (ValidationException $e) {
                $errors[] = ['index' => $index, 'error' => "{$label}: ".collect($e->errors())->flatten()->first()];

                continue;
            }

            $censusCode = trim((string) ($props['OBJ_ID'] ?? ''));
            $censusCode = $censusCode !== '' ? $censusCode : null;
            if ($censusCode !== null) {
                if (isset($censusInFile[$censusCode])) {
                    $errors[] = ['index' => $index, 'error' => "{$label}: OBJ_ID '{$censusCode}' duplicato nel file."];

                    continue;
                }
                $censusInFile[$censusCode] = true;
            }

            $treeData = null;
            if ($type->requires_tree_record) {
                $treeData = $this->treeData($props, $index, $label, $errors);
                if ($treeData === false) {
                    continue; // errore già registrato
                }
            }

            $assetId = (string) Str::uuid7();
            $assetRows[] = [
                'id' => $assetId,
                'tenant_id' => $tenantId,
                'area_id' => $area->id,
                'object_type_id' => $type->id,
                'census_code' => $censusCode,
                'status' => self::STATO_MAP[strtolower(trim((string) ($props['STATO'] ?? '')))] ?? 'active',
                'geom' => $ewkb,
                'survey_method' => 'shapefile_import',
                'valid_from' => $this->fromCamDate($props['DATA_INI'] ?? null) ?? now()->toDateString(),
                'valid_to' => $this->fromCamDate($props['DATA_FINE'] ?? null),
                'attributes' => json_encode([]),
                'notes' => ($n = trim((string) ($props['NOTE'] ?? ''))) !== '' ? $n : null,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($type->requires_tree_record) {
                $treeRows[] = [
                    'asset_id' => $assetId,
                    'tenant_id' => $tenantId,
                    ...$treeData,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Codici censimento già presenti nel database
        $codes = array_keys($censusInFile);
        if ($codes !== []) {
            $existing = Asset::query()->whereIn('census_code', $codes)->pluck('census_code')->all();
            if ($existing !== []) {
                $existingSet = array_flip($existing);
                $keptIds = [];
                $assetRows = array_values(array_filter($assetRows, function ($row) use ($existingSet, &$errors, &$keptIds) {
                    if ($row['census_code'] !== null && isset($existingSet[$row['census_code']])) {
                        $errors[] = ['index' => null, 'error' => "OBJ_ID '{$row['census_code']}' già presente nel censimento."];

                        return false;
                    }
                    $keptIds[$row['id']] = true;

                    return true;
                }));
                $treeRows = array_values(array_filter($treeRows, fn ($t) => isset($keptIds[$t['asset_id']])));
            }
        }

        $imported = 0;
        if (! $dryRun && $assetRows !== []) {
            DB::transaction(function () use ($assetRows, $treeRows, &$imported) {
                foreach (array_chunk($assetRows, 200) as $chunk) {
                    Asset::insert($chunk);
                    $imported += count($chunk);
                }
                foreach (array_chunk($treeRows, 200) as $chunk) {
                    Tree::insert($chunk);
                }
            });
        }

        return [
            'total' => count($features),
            'importable' => count($assetRows),
            'imported' => $imported,
            'skipped' => count($features) - count($assetRows),
            'errors' => array_slice($errors, 0, 50),
            'errors_total' => count($errors),
            'dry_run' => $dryRun,
        ];
    }

    /** Campi vegetazione delle Master P1/L1/S1 -> scheda albero. */
    private function treeData(array $props, int $index, string $label, array &$errors): array|false
    {
        $candidate = [
            'plant_number' => ($v = trim((string) ($props['PT'] ?? ''))) !== '' ? $v : null,
            'genus' => ($v = trim((string) ($props['GENERE'] ?? ''))) !== '' ? $v : null,
            'species' => ($v = trim((string) ($props['SPECIE'] ?? ''))) !== '' ? $v : null,
            'cultivar' => ($v = trim((string) ($props['VARIETA'] ?? ''))) !== '' ? $v : null,
            'height_m' => $this->numeric($props['H_M'] ?? null),
            'dbh_cm' => $this->numeric($props['DIAM_TRONC'] ?? null),
            'crown_diameter_m' => $this->numeric($props['DIAM_CHIOM'] ?? null),
        ];

        $validator = Validator::make($candidate, [
            'plant_number' => ['nullable', 'string', 'max:20'],
            'genus' => ['nullable', 'string', 'max:100'],
            'species' => ['nullable', 'string', 'max:150'],
            'cultivar' => ['nullable', 'string', 'max:150'],
            'height_m' => ['nullable', 'numeric', 'min:0', 'max:150'],
            'dbh_cm' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'crown_diameter_m' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        if ($validator->fails()) {
            $errors[] = ['index' => $index, 'error' => "{$label}: ".$validator->errors()->first()];

            return false;
        }

        return $candidate;
    }

    private function numeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = str_replace(',', '.', (string) $value);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /** Data GGMMAAAA dei tracciati record MD -> data ISO. */
    private function fromCamDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if (! preg_match('/^(\d{2})(\d{2})(\d{4})$/', $value, $m)) {
            return null;
        }
        if (! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }

        return sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
    }
}
