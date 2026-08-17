<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Giornate annullate di un ordine pianificato su più giorni: l'ordine
     * resta uno solo (codice, cliente, consuntivo), ma in agenda si può
     * togliere una singola giornata senza cancellare tutto il lavoro.
     * Elenco di date "AAAA-MM-GG" dentro il periodo previsto.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE work_orders ADD COLUMN cancelled_days jsonb NOT NULL DEFAULT '[]'::jsonb");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE work_orders DROP COLUMN cancelled_days');
    }
};
