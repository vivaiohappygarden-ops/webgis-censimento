<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La voce di preventivo può essere collegata all'elemento censito da cui
 * riprende la misura (superficie del prato, lunghezza della siepe): senza
 * il riferimento non c'è da dove prendere la quantità.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE estimate_items ADD COLUMN asset_id uuid REFERENCES assets(id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE estimate_items DROP COLUMN asset_id');
    }
};
