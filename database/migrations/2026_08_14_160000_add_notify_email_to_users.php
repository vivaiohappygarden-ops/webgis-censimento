<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Preferenza per il riepilogo email delle scadenze: attiva per tutti,
     * l'amministratore la spegne dalla pagina Utenti per chi non lo vuole.
     * Il riepilogo parte comunque solo per amministratori e tecnici attivi.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE users ADD COLUMN notify_email boolean NOT NULL DEFAULT true');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP COLUMN notify_email');
    }
};
