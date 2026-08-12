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

    /**
     * Campi standard del Modello Dati che viaggiano negli attributi: se il
     * tipo non li ha ancora tra i campi personalizzati, l'import li
     * definisce, così la scheda li mostra e li valida come gli altri.
     */
    private const MD_CUSTOM_FIELDS = [
        'genere' => ['label' => 'Genere', 'field_type' => 'text', 'cam_field' => 'GENERE'],
        'specie' => ['label' => 'Specie', 'field_type' => 'text', 'cam_field' => 'SPECIE'],
        'varieta' => ['label' => 'Varietà', 'field_type' => 'text', 'cam_field' => 'VARIETA'],
        'altezza_m' => ['label' => 'Altezza (m)', 'field_type' => 'number', 'cam_field' => 'H_m'],
        'larghezza_m' => ['label' => 'Larghezza (m)', 'field_type' => 'number', 'cam_field' => 'LARG_m'],
    ];

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

            // Tetto sulla dimensione DECOMPRESSA: il limite dell'upload vale solo
            // per lo zip compresso, e con DEFLATE una bomba da 50 MB può
            // espandersi a decine di GB saturando memoria e disco
            $totalUncompressed = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $totalUncompressed += (int) ($zip->statIndex($i)['size'] ?? 0);
            }
            if ($totalUncompressed > 200 * 1024 * 1024) {
                throw ValidationException::withMessages([
                    'file' => 'Archivio troppo grande una volta estratto (oltre 200 MB).',
                ]);
            }

            $shpNames = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                // Solo i componenti shapefile, senza percorsi (protezione zip-slip);
                // nomi normalizzati in minuscolo (gli archivi ESRI storici usano .SHP/.PRJ)
                $name = strtolower(basename($zip->getNameIndex($i)));
                $ext = pathinfo($name, PATHINFO_EXTENSION);
                if (! in_array($ext, ['shp', 'shx', 'dbf', 'prj', 'cpg'], true)) {
                    continue;
                }

                $stream = $zip->getStream($zip->getNameIndex($i));
                $out = fopen("{$dir}/{$name}", 'wb');
                if ($stream === false || $out === false || stream_copy_to_stream($stream, $out) === false) {
                    throw ValidationException::withMessages(['file' => 'Estrazione dello zip non riuscita.']);
                }
                fclose($out);
                if (is_resource($stream)) {
                    fclose($stream);
                }

                if ($ext === 'shp') {
                    $shpNames[] = $name;
                }
            }
            $zip->close();

            if ($shpNames === []) {
                throw ValidationException::withMessages(['file' => 'Nessun file .shp trovato nello zip.']);
            }
            if (count($shpNames) > 1) {
                throw ValidationException::withMessages([
                    'file' => 'Lo zip contiene più shapefile ('.implode(', ', $shpNames).'): caricane uno alla volta.',
                ]);
            }
            $shpName = $shpNames[0];
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

            try {
                $process->setTimeout(120)->run();
            } catch (\Symfony\Component\Process\Exception\ExceptionInterface $e) {
                throw ValidationException::withMessages([
                    'file' => 'Conversione shapefile interrotta (file troppo complesso o strumento non disponibile).',
                ]);
            }

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
        $warnings = [];
        $assetRows = [];
        $treeRows = [];
        $censusInFile = [];
        $customFieldNeeds = [];

        foreach ($features as $index => $feature) {
            $label = 'feature #'.($index + 1);
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $props = array_change_key_case($props, CASE_UPPER);

            $codice = trim((string) ($props['CODICE'] ?? ''));
            if ($codice === '' || ! $typesByCode->has($codice)) {
                $errors[] = ['index' => $index, 'error' => "{$label}: CODICE '{$codice}' assente o non presente nel catalogo."];

                continue;
            }
            // Il nostro export S3 include i perimetri delle aree di gestione:
            // reimportarli creerebbe elementi fittizi (le aree si gestiscono
            // nella pagina Territorio)
            if ($codice === 'S325500') {
                $warnings[] = "{$label}: CODICE S325500 (limite area di gestione) ignorato: i perimetri si gestiscono nel Territorio.";

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
                $treeData = $this->treeData($props, $index, $label, $errors, $warnings);
                if ($treeData === false) {
                    continue; // errore già registrato
                }
            }

            $statoRaw = strtolower(trim((string) ($props['STATO'] ?? '')));
            if ($statoRaw !== '' && ! isset(self::STATO_MAP[$statoRaw])) {
                $warnings[] = "{$label}: STATO '{$props['STATO']}' non riconosciuto, impostato 'Pianta viva'.";
            }

            // Le date devono rispettare il vincolo del DB (fine >= inizio):
            // scoprirlo all'insert romperebbe il contratto dry-run/import
            $validFrom = $this->fromCamDate($props['DATA_INI'] ?? null) ?? now()->toDateString();
            $validTo = $this->fromCamDate($props['DATA_FINE'] ?? null);
            if ($validTo !== null && $validTo < $validFrom) {
                $errors[] = ['index' => $index, 'error' => "{$label}: DATA_FINE ({$validTo}) precedente a DATA_INI ({$validFrom})."];

                continue;
            }

            $attributes = $this->layerAttributes($type->cam_layer, $props, $label, $warnings);
            foreach (array_keys($attributes) as $key) {
                $customFieldNeeds["{$type->id}|{$key}"] = ['object_type_id' => $type->id, 'key' => $key];
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
                'surveyed_at' => $this->fromCamDate($props['DATA_RIL'] ?? null),
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
                'attributes' => $attributes === [] ? '{}' : json_encode($attributes),
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
            DB::transaction(function () use ($assetRows, $treeRows, $customFieldNeeds, $tenantId, &$imported) {
                foreach ($customFieldNeeds as $need) {
                    \App\Models\CustomField::query()->withoutGlobalScopes()->firstOrCreate(
                        ['tenant_id' => $tenantId, 'object_type_id' => $need['object_type_id'], 'key' => $need['key']],
                        [...self::MD_CUSTOM_FIELDS[$need['key']], 'required' => false, 'sort_order' => 90],
                    );
                }
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
            'warnings' => array_slice($warnings, 0, 50),
            'warnings_total' => count($warnings),
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Campi specifici di layer -> attributi dell'elemento (inverso di
     * CamExporter): vegetazione per siepi e superfici verdi non arboree,
     * larghezza per gli elementi lineari. Le misure calcolate (LUNG_m,
     * AREA_mq, PERIM_m) si rigenerano dalla geometria e non si importano.
     */
    private function layerAttributes(?string $layer, array $props, string $label, array &$warnings): array
    {
        $attributes = [];

        if (in_array($layer, ['L1', 'S1'], true)) {
            foreach (['GENERE' => 'genere', 'SPECIE' => 'specie', 'VARIETA' => 'varieta'] as $field => $key) {
                $value = trim((string) ($props[$field] ?? ''));
                if ($value !== '') {
                    $attributes[$key] = $value;
                }
            }
        }
        if ($layer === 'L1') {
            $attributes['altezza_m'] = $this->numeric($props['H_M'] ?? null, "{$label}: H_m", $warnings);
        }
        if (in_array($layer, ['L1', 'L2', 'L3'], true)) {
            $attributes['larghezza_m'] = $this->numeric($props['LARG_M'] ?? null, "{$label}: LARG_m", $warnings);
        }

        return array_filter($attributes, fn ($v) => $v !== null);
    }

    /** Campi vegetazione delle Master P1/L1/S1 -> scheda albero. */
    private function treeData(array $props, int $index, string $label, array &$errors, array &$warnings): array|false
    {
        $candidate = [
            'plant_number' => ($v = trim((string) ($props['PT'] ?? ''))) !== '' ? $v : null,
            'genus' => ($v = trim((string) ($props['GENERE'] ?? ''))) !== '' ? $v : null,
            'species' => ($v = trim((string) ($props['SPECIE'] ?? ''))) !== '' ? $v : null,
            'cultivar' => ($v = trim((string) ($props['VARIETA'] ?? ''))) !== '' ? $v : null,
            'height_m' => $this->numeric($props['H_M'] ?? null, "{$label}: H_m", $warnings),
            'dbh_cm' => $this->numeric($props['DIAM_TRONC'] ?? null, "{$label}: DIAM_TRONC", $warnings),
            'crown_diameter_m' => $this->numeric($props['DIAM_CHIOM'] ?? null, "{$label}: DIAM_CHIOM", $warnings),
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

    private function numeric(mixed $value, string $context, array &$warnings): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = str_replace(',', '.', (string) $value);
        if (! is_numeric($normalized)) {
            $warnings[] = "{$context}: valore '{$value}' non numerico, ignorato.";

            return null;
        }

        return (float) $normalized;
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
