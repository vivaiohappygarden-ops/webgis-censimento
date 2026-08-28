<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sfondi cartografici per committente.
 *
 * I capitolati chiedono spesso "ortofoto comunale" o "carta tecnica
 * regionale": ogni committente puo' avere i suoi sfondi (a riquadri z/x/y o
 * WMS), che si aggiungono alla mappa del gestionale e a quella del suo
 * portale pubblico. Le voci ammesse e la costruzione degli indirizzi stanno
 * in App\Services\Carto\SfondiCommittente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->jsonb('basemaps')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('basemaps');
        });
    }
};
