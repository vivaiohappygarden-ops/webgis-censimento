<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il rilevatore di una valutazione di stabilita' scelto dall'elenco dei
 * rilevatori abilitati dell'organizzazione: la valutazione conserva una copia
 * dei suoi dati (titolo, albo, partita IVA) cosi' la perizia stampa quello
 * che valeva quel giorno anche se l'elenco cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tree_assessments', function (Blueprint $table) {
            $table->jsonb('assessor_details')->nullable()->after('assessor_external');
        });
    }

    public function down(): void
    {
        Schema::table('tree_assessments', function (Blueprint $table) {
            $table->dropColumn('assessor_details');
        });
    }
};
