<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Console della piattaforma (27/09/2026): la qualifica di "gestore della
 * piattaforma" e' una spunta sull'utente, data solo dal terminale
 * (php artisan piattaforma:gestore). Chi ce l'ha vede tutte le
 * organizzazioni con i loro numeri, ne crea di nuove, le sospende e
 * riattiva, ed entra in assistenza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_manager')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_manager');
        });
    }
};
