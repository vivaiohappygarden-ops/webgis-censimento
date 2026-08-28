<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le imprese esterne appartengono a un committente (indicazione committente
 * 28/08/2026): non sono una lista generica del gestionale. Le squadre
 * interne restano senza committente (client_id nullo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->uuid('client_id')->nullable();
            $table->foreign('client_id')->references('id')->on('clients');
            $table->index(['tenant_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'client_id']);
            $table->dropForeign(['client_id']);
            $table->dropColumn('client_id');
        });
    }
};
