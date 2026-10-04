<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zone di un'organizzazione (punto 4 dell'elenco del committente del
 * 04/10/2026): un'azienda con sedi in piu' parti d'Italia divide il
 * territorio in zone, ogni zona raccoglie dei committenti, e un utente
 * assegnato a una o piu' zone vede e tocca solo quello che sta sotto quei
 * committenti. Chi non ha zone e' "centrale" e vede tutto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name', 150);
            $table->string('code', 30)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('zone_client', function (Blueprint $table) {
            $table->uuid('zone_id');
            $table->uuid('client_id');
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['zone_id', 'client_id']);
            $table->foreign('zone_id')->references('id')->on('zones')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->index('client_id');
        });

        Schema::create('zone_user', function (Blueprint $table) {
            $table->uuid('zone_id');
            $table->uuid('user_id');
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['zone_id', 'user_id']);
            $table->foreign('zone_id')->references('id')->on('zones')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_user');
        Schema::dropIfExists('zone_client');
        Schema::dropIfExists('zones');
    }
};
