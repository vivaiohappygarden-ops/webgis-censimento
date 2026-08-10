<?php

namespace App\Services\Import;

use App\Models\Area;
use App\Models\Asset;
use App\Models\CatalogObjectType;
use App\Support\Geometry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Import censimento da GeoJSON (FeatureCollection).
 *
 * Il tipo oggetto di ogni feature è risolto dalla proprietà "codice"
 * (codice catalogo, es. P103108) o, in mancanza, dal tipo di default scelto.
 * Mai importare silenziosamente: il dry-run restituisce il report completo
 * e l'import vero è transazionale (o tutto o niente).
 */
class GeoJsonImporter
{
    public const MAX_FEATURES = 5000;

    /** Proprietà riconosciute oltre agli attributi custom del tipo. */
    private const CODE_KEYS = ['codice', 'CODICE', 'code'];

    private const CENSUS_KEYS = ['census_code', 'OBJ_ID', 'obj_id', 'codice_censimento'];

    private const NOTES_KEYS = ['note', 'NOTE', 'notes'];

    public function run(array $geojson, Area $area, ?CatalogObjectType $defaultType, bool $dryRun = true): array
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
        $fieldKeysByType = [];

        $errors = [];
        $rows = [];
        $droppedProperties = [];
        $censusInFile = [];

        foreach ($features as $index => $feature) {
            $label = 'feature #'.($index + 1);
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];

            $type = $this->resolveType($props, $typesByCode) ?? $defaultType;
            if (! $type) {
                $errors[] = ['index' => $index, 'error' => "{$label}: nessuna proprietà 'codice' valida e nessun tipo di default scelto."];

                continue;
            }

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

            $censusCode = $this->firstProperty($props, self::CENSUS_KEYS);
            if ($censusCode !== null) {
                $censusCode = (string) $censusCode;
                if (isset($censusInFile[$censusCode])) {
                    $errors[] = ['index' => $index, 'error' => "{$label}: codice censimento '{$censusCode}' duplicato nel file."];

                    continue;
                }
                $censusInFile[$censusCode] = true;
            }

            // Attributi: solo le chiavi definite nel Catalog Manager per il tipo
            $fieldKeysByType[$type->id] ??= $type->customFields()->pluck('key')->all();
            $known = $fieldKeysByType[$type->id];
            $reserved = [...self::CODE_KEYS, ...self::CENSUS_KEYS, ...self::NOTES_KEYS];
            $attributes = array_intersect_key($props, array_flip($known));
            foreach (array_diff(array_keys($props), $known, $reserved) as $dropped) {
                $droppedProperties[$dropped] = true;
            }

            $rows[] = [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'area_id' => $area->id,
                'object_type_id' => $type->id,
                'census_code' => $censusCode,
                'status' => 'active',
                'geom' => $ewkb,
                'survey_method' => 'shapefile_import',
                'attributes' => json_encode($attributes),
                'notes' => ($n = $this->firstProperty($props, self::NOTES_KEYS)) !== null ? (string) $n : null,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Codici censimento già presenti nel database
        $codes = array_keys($censusInFile);
        if ($codes !== []) {
            $existing = Asset::query()->whereIn('census_code', $codes)->pluck('census_code')->all();
            if ($existing !== []) {
                $existingSet = array_flip($existing);
                $rows = array_values(array_filter($rows, function ($row) use ($existingSet, &$errors) {
                    if ($row['census_code'] !== null && isset($existingSet[$row['census_code']])) {
                        $errors[] = ['index' => null, 'error' => "Codice censimento '{$row['census_code']}' già presente nel censimento."];

                        return false;
                    }

                    return true;
                }));
            }
        }

        $imported = 0;
        if (! $dryRun && $rows !== []) {
            DB::transaction(function () use ($rows, &$imported) {
                foreach (array_chunk($rows, 200) as $chunk) {
                    Asset::insert($chunk);
                    $imported += count($chunk);
                }
            });
        }

        return [
            'total' => count($features),
            'importable' => count($rows),
            'imported' => $imported,
            'skipped' => count($features) - count($rows),
            'errors' => array_slice($errors, 0, 50),
            'errors_total' => count($errors),
            'dropped_properties' => array_keys($droppedProperties),
            'dry_run' => $dryRun,
        ];
    }

    private function resolveType(array $props, $typesByCode): ?CatalogObjectType
    {
        foreach (self::CODE_KEYS as $key) {
            if (! empty($props[$key]) && $typesByCode->has((string) $props[$key])) {
                return $typesByCode->get((string) $props[$key]);
            }
        }

        return null;
    }

    private function firstProperty(array $props, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (isset($props[$key]) && $props[$key] !== '') {
                return $props[$key];
            }
        }

        return null;
    }
}
