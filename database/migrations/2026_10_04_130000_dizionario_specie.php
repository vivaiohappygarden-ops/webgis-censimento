<?php

use App\Services\Botanica\DizionarioSpecie;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Il dizionario delle specie: voci di serie (tenant_id nullo) piu' quelle di
 * ogni organizzazione. La migrazione installa le voci di serie dal CSV, cosi'
 * un'installazione gia' in piedi le riceve al primo aggiornamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tree_species', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('genus', 100)->nullable();
            $table->string('species', 150);
            $table->string('cultivar', 100)->nullable();
            $table->string('family', 100)->nullable();
            $table->string('common_name', 150)->nullable();
            $table->jsonb('synonyms')->default('[]');
            $table->text('search_text')->nullable();
            $table->string('source', 20)->default('serie');
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });
        // Una sola voce per specie e cultivar, per organizzazione (le voci di serie sono "l'organizzazione nulla")
        DB::statement("CREATE UNIQUE INDEX tree_species_voce_unica ON tree_species (COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000'::uuid), lower(species), lower(COALESCE(cultivar, '')))");

        DizionarioSpecie::installaDiSerie();
    }

    public function down(): void
    {
        Schema::dropIfExists('tree_species');
    }
};
