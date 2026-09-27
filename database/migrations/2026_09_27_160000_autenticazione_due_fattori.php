<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verifica in due passaggi (27/09/2026). Le colonne mfa_enabled e mfa_secret
 * c'erano dal primo giorno e non erano mai state usate; qui si aggiungono la
 * data della conferma, i codici di recupero (solo impronte) e l'ultimo passo
 * temporale speso, perche' un codice non valga due volte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('mfa_confirmed_at')->nullable();
            $table->jsonb('mfa_recovery_codes')->nullable();
            $table->bigInteger('mfa_last_step')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_confirmed_at', 'mfa_recovery_codes', 'mfa_last_step']);
        });
    }
};
