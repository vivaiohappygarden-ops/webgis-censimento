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
     * Pubblici: li usa anche l'import generico con mappatura delle colonne.
     */
    public const MD_CUSTOM_FIELDS = [
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
        // Il tracciato CAM viaggia solo come shapefile zippato o GeoJSON:
        // gli altri formati passano dall'import generico
        return (new ConvertitoreGeo)->aGeoJson($file, ['zip', 'json', 'geojson']);
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
        // Definizioni dei campi personalizzati per tipo (anche le eliminate:
        // un campo standard MD cancellato viene ripristinato al vero import)
        $fieldDefs = \App\Models\CustomField::query()
            ->withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
            ->withTrashed()
            ->where('tenant_id', $tenantId)
            ->whereIn('object_type_id', $typesByCode->pluck('id'))
            ->get()
            ->groupBy('object_type_id')
            ->map(fn ($group) => $group->keyBy('key'));

        $errors = [];
        $warnings = [];
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
            $validFrom = LetturaValori::dataCam($props['DATA_INI'] ?? null) ?? now()->toDateString();
            $validTo = LetturaValori::dataCam($props['DATA_FINE'] ?? null);
            if ($validTo !== null && $validTo < $validFrom) {
                $errors[] = ['index' => $index, 'error' => "{$label}: DATA_FINE ({$validTo}) precedente a DATA_INI ({$validFrom})."];

                continue;
            }

            $attributes = $this->conformAttributes(
                $this->layerAttributes($type->cam_layer, $props, $label, $warnings),
                $fieldDefs->get($type->id) ?? collect(),
                $label,
                $warnings,
            );

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
                'surveyed_at' => LetturaValori::data($props['DATA_RIL'] ?? null, "{$label}: DATA_RIL", $warnings),
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
                'attributes' => $attributes === [] ? '{}' : (json_encode($attributes) ?: '{}'),
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

        // I campi standard MD servono solo per le righe che verranno davvero
        // importate: una riga scartata non deve mutare il catalogo
        $customFieldNeeds = [];
        foreach ($assetRows as $row) {
            foreach (array_keys(json_decode($row['attributes'], true) ?: []) as $key) {
                if (isset(self::MD_CUSTOM_FIELDS[$key])) {
                    $customFieldNeeds["{$row['object_type_id']}|{$key}"] = ['object_type_id' => $row['object_type_id'], 'key' => $key];
                }
            }
        }

        $imported = 0;
        if (! $dryRun && $assetRows !== []) {
            DB::transaction(function () use ($assetRows, $treeRows, $customFieldNeeds, $tenantId, &$imported) {
                self::assicuraCampiMd($customFieldNeeds, $tenantId);
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
     * Crea (o ripristina, se eliminati) i campi personalizzati standard del
     * Modello Dati necessari alle righe importate. Da chiamare dentro la
     * transazione dell'import: lo usano il percorso CAM e quello generico.
     */
    public static function assicuraCampiMd(array $needs, string $tenantId): void
    {
        foreach ($needs as $need) {
            $field = \App\Models\CustomField::query()
                ->withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
                ->withTrashed()
                ->where('tenant_id', $tenantId)
                ->where('object_type_id', $need['object_type_id'])
                ->where('key', $need['key'])
                ->first();
            if ($field !== null && $field->trashed()) {
                // Un campo eliminato torna in vita: la scheda deve
                // mostrare e accettare il valore appena importato
                $field->restore();
            } elseif ($field === null) {
                \App\Models\CustomField::create([
                    'tenant_id' => $tenantId,
                    'object_type_id' => $need['object_type_id'],
                    'key' => $need['key'],
                    ...self::MD_CUSTOM_FIELDS[$need['key']], 'required' => false, 'sort_order' => 90,
                ]);
            }
        }
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
                // I byte NUL non sono rappresentabili in jsonb: scoprirlo
                // all'insert romperebbe il contratto dry-run/import
                $value = trim(str_replace("\0", '', (string) ($props[$field] ?? '')));
                if ($value !== '') {
                    $attributes[$key] = $value;
                }
            }
        }
        if ($layer === 'L1') {
            $attributes['altezza_m'] = LetturaValori::numero($props['H_M'] ?? null, "{$label}: H_m", $warnings);
        }
        if (in_array($layer, ['L1', 'L2', 'L3'], true)) {
            $attributes['larghezza_m'] = LetturaValori::numero($props['LARG_M'] ?? null, "{$label}: LARG_m", $warnings);
        }

        return array_filter($attributes, fn ($v) => $v !== null);
    }

    /**
     * I valori importati devono rispettare la definizione del campo
     * (esistente, anche se eliminata e in via di ripristino, o quella
     * standard MD che verrà creata): un elemento non deve nascere in uno
     * stato che la sua stessa scheda rifiuterebbe al salvataggio.
     */
    private function conformAttributes(array $attributes, \Illuminate\Support\Collection $definitions, string $label, array &$warnings): array
    {
        foreach ($attributes as $key => $value) {
            $definition = $definitions->get($key);
            $fieldType = $definition->field_type ?? self::MD_CUSTOM_FIELDS[$key]['field_type'];

            if (in_array($fieldType, ['number', 'integer'], true)) {
                if ($value < 0) {
                    $warnings[] = "{$label}: {$key} negativo ({$value}), ignorato.";
                    unset($attributes[$key]);
                } elseif ($fieldType === 'integer') {
                    $attributes[$key] = (int) round($value);
                }
            } elseif ($fieldType === 'select') {
                if (! in_array($value, $definition->options ?? [], true)) {
                    $shown = mb_substr((string) $value, 0, 50);
                    $warnings[] = "{$label}: '{$shown}' non è tra le opzioni del campo {$key}, ignorato.";
                    unset($attributes[$key]);
                }
            } elseif (in_array($fieldType, ['text', 'textarea'], true)) {
                if (mb_strlen((string) $value) > 2000) {
                    $warnings[] = "{$label}: {$key} oltre 2000 caratteri, troncato.";
                    $attributes[$key] = mb_substr((string) $value, 0, 2000);
                }
            } else {
                $warnings[] = "{$label}: il campo {$key} è di tipo '{$fieldType}' e non si importa dal tracciato, ignorato.";
                unset($attributes[$key]);
            }
        }

        return $attributes;
    }

    /** Campi vegetazione delle Master P1/L1/S1 -> scheda albero. */
    private function treeData(array $props, int $index, string $label, array &$errors, array &$warnings): array|false
    {
        $candidate = [
            'plant_number' => ($v = trim((string) ($props['PT'] ?? ''))) !== '' ? $v : null,
            'genus' => ($v = trim((string) ($props['GENERE'] ?? ''))) !== '' ? $v : null,
            'species' => ($v = trim((string) ($props['SPECIE'] ?? ''))) !== '' ? $v : null,
            'cultivar' => ($v = trim((string) ($props['VARIETA'] ?? ''))) !== '' ? $v : null,
            'height_m' => LetturaValori::numero($props['H_M'] ?? null, "{$label}: H_m", $warnings),
            'dbh_cm' => LetturaValori::numero($props['DIAM_TRONC'] ?? null, "{$label}: DIAM_TRONC", $warnings),
            'crown_diameter_m' => LetturaValori::numero($props['DIAM_CHIOM'] ?? null, "{$label}: DIAM_CHIOM", $warnings),
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
}
