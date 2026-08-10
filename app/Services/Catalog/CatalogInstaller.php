<?php

namespace App\Services\Catalog;

use App\Models\CatalogMainType;
use App\Models\CatalogObjectType;
use App\Models\CatalogSubType;
use App\Models\Organization;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Installa per un tenant il catalogo oggetti del "Modello dati per il
 * censimento del verde urbano" v2.1 (seed CSV estratto dal documento ufficiale).
 * Il catalogo installato è un fork del tenant: personalizzabile senza toccare il seed.
 */
class CatalogInstaller
{
    public const MAIN_TYPE_NAMES = [
        '1' => 'Vegetazione',
        '2' => 'Arredo urbano',
        '3' => 'Fruizione e gestione',
        '4' => 'Fattori ambientali',
    ];

    /** Codici che alla creazione dell'asset generano anche la scheda albero. */
    public const TREE_CODES = ['P103108'];

    /**
     * Idempotente: se il tenant ha già un catalogo installato non fa nulla;
     * l'installazione è transazionale (mai un catalogo a metà).
     *
     * @return array{main_types: int, sub_types: int, object_types: int, skipped?: bool}
     */
    public function install(Organization $organization, ?string $csvPath = null): array
    {
        $existing = CatalogObjectType::withoutGlobalScopes()
            ->where('tenant_id', $organization->id)
            ->count();

        if ($existing > 0) {
            return [
                'main_types' => CatalogMainType::withoutGlobalScopes()->where('tenant_id', $organization->id)->count(),
                'sub_types' => CatalogSubType::withoutGlobalScopes()->where('tenant_id', $organization->id)->count(),
                'object_types' => $existing,
                'skipped' => true,
            ];
        }

        return \Illuminate\Support\Facades\DB::transaction(
            fn () => $this->doInstall($organization, $csvPath)
        );
    }

    /** @return array{main_types: int, sub_types: int, object_types: int} */
    private function doInstall(Organization $organization, ?string $csvPath = null): array
    {
        $csvPath ??= database_path('seeders/data/catalogo_md_v21.csv');

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Seed catalogo non trovato: {$csvPath}");
        }

        $header = fgetcsv($handle);
        $expected = ['codice', 'geo', 'tp_code', 'tp_nome', 'ts_code', 'ts_nome', 'att_code', 'descrizione', 'foto_riferimento_rilevante'];
        if ($header !== $expected) {
            fclose($handle);
            throw new RuntimeException('Header del seed catalogo inatteso: '.implode(',', $header ?: []));
        }

        $mainTypes = [];
        $subTypes = [];
        $objectRows = [];
        $now = now();

        $line = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if (count($row) < 9) {
                fclose($handle);
                throw new RuntimeException("Riga {$line} del seed catalogo malformata: attesi 9 campi, trovati ".count($row));
            }
            [$code, $geo, $tpCode, $tpName, $tsCode, $tsName, , $description] = $row;

            if (! isset($mainTypes[$tpCode])) {
                $mainTypes[$tpCode] = CatalogMainType::create([
                    'tenant_id' => $organization->id,
                    'code' => $tpCode,
                    'name' => self::MAIN_TYPE_NAMES[$tpCode] ?? $tpName,
                    'is_cam' => true,
                    'sort_order' => (int) $tpCode,
                ]);
            }

            $subKey = "{$tpCode}.{$tsCode}";
            if (! isset($subTypes[$subKey])) {
                $subTypes[$subKey] = CatalogSubType::create([
                    'tenant_id' => $organization->id,
                    'main_type_id' => $mainTypes[$tpCode]->id,
                    'code' => $tsCode,
                    'name' => $tsName,
                    'is_cam' => true,
                    'sort_order' => (int) $tsCode,
                ]);
            }

            $objectRows[] = [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $organization->id,
                'sub_type_id' => $subTypes[$subKey]->id,
                'code' => $code,
                'name' => $description,
                'allowed_geometry' => $geo,
                'cam_layer' => $geo.$tpCode,
                'is_cam' => true,
                'requires_tree_record' => in_array($code, self::TREE_CODES, true),
                'style' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        fclose($handle);

        foreach (array_chunk($objectRows, 100) as $chunk) {
            CatalogObjectType::insert($chunk);
        }

        return [
            'main_types' => count($mainTypes),
            'sub_types' => count($subTypes),
            'object_types' => count($objectRows),
        ];
    }
}
