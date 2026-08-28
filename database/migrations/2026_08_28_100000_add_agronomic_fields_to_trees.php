<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anagrafica agronomica dell'albero: quattro campi a dizionario.
 *
 * - social_position: posizione sociale (isolato, filare, gruppo, area boscata)
 * - target: bersaglio, cioe' la frequentazione sotto la chioma
 * - growth_site: sito di crescita (dal prato alla buca nell'asfalto)
 * - age_qualifier: l'eta' in anni e' precisa o stimata
 *
 * Le voci ammesse stanno in config/agronomia.php, che e' anche la fonte
 * delle tendine; la fase fisiologica a sei stadi riusa la colonna age_class
 * gia' esistente (i quattro stadi storici sono un sottoinsieme dei sei).
 * Le colonne sono di testo semplice come age_class e vegetative_state, e la
 * fotografia di versione (to_jsonb della riga trees) le riprende da sola.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trees', function (Blueprint $table) {
            $table->text('social_position')->nullable();
            $table->text('target')->nullable();
            $table->text('growth_site')->nullable();
            $table->text('age_qualifier')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trees', function (Blueprint $table) {
            $table->dropColumn(['social_position', 'target', 'growth_site', 'age_qualifier']);
        });
    }
};
