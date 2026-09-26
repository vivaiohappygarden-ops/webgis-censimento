<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Preferenze personali dell'utente (dal 26/09/2026): la prima e' la scelta
 * fra la nuova interfaccia del gestionale e quella precedente. Un jsonb come
 * quello delle organizzazioni: le preferenze che verranno non chiederanno una
 * colonna a testa.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users ADD COLUMN IF NOT EXISTS settings jsonb NOT NULL DEFAULT '{}'::jsonb");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS settings');
    }
};
