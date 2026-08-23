<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indici per la ricerca a parole.
 *
 * La ricerca cerca un pezzo di parola in mezzo al testo ("%rossi%"): un indice
 * normale non serve a niente, perché sa ordinare solo per inizio. Gli indici a
 * trigrammi (pg_trgm, già installata) indicizzano gruppi di tre lettere e
 * reggono anche la ricerca in mezzo.
 *
 * Senza, con qualche decina di migliaia di alberi ogni ricerca leggerebbe
 * l'intera tabella.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const CAMPI = [
        'assets' => ['census_code', 'notes'],
        'trees' => ['genus', 'species', 'common_name'],
        'clients' => ['name'],
        'areas' => ['name'],
        'localities' => ['name'],
        'sites' => ['name'],
        'work_orders' => ['code', 'title'],
        'catalog_object_types' => ['name'],
    ];

    public function up(): void
    {
        foreach (self::CAMPI as $tabella => $colonne) {
            foreach ($colonne as $colonna) {
                DB::statement(sprintf(
                    'CREATE INDEX IF NOT EXISTS ix_trgm_%1$s_%2$s ON %1$s USING gin (%2$s gin_trgm_ops)',
                    $tabella, $colonna,
                ));
            }
        }
    }

    public function down(): void
    {
        foreach (self::CAMPI as $tabella => $colonne) {
            foreach ($colonne as $colonna) {
                DB::statement("DROP INDEX IF EXISTS ix_trgm_{$tabella}_{$colonna}");
            }
        }
    }
};
