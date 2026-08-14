<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Dati estesi della scheda VTA professionale, quelli che compaiono
     * nella perizia di stabilità: inquadramento del contesto, giudizio
     * complessivo, difetti per parte dell'albero, interferenze,
     * integrazione della valutazione, priorità e conclusioni.
     * Un solo campo strutturato: sono voci descrittive che viaggiano
     * insieme e si leggono insieme nel documento.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE tree_assessments ADD COLUMN survey jsonb NOT NULL DEFAULT '{}'::jsonb");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tree_assessments DROP COLUMN survey');
    }
};
