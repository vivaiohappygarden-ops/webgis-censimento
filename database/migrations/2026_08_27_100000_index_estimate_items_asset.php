<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indice sul riferimento all'elemento censito: PostgreSQL non indicizza da
 * solo le colonne con chiave esterna, e il controllo che blocca
 * l'eliminazione di un elemento riferito da una voce di preventivo cerca
 * proprio per asset_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX ix_estimate_items_asset ON estimate_items (asset_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX ix_estimate_items_asset');
    }
};
