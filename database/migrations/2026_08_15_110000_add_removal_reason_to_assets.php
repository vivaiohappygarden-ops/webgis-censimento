<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Motivo dell'abbattimento/rimozione valido per qualunque elemento censito
 * (non solo alberi): siepi estirpate, arredi rimossi, aiuole dismesse.
 * La data resta su assets.valid_to (e, per gli alberi, su trees.removed_on,
 * da cui dipende il bilancio arboreo).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE assets ADD COLUMN removal_reason text');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE assets DROP COLUMN removal_reason');
    }
};
