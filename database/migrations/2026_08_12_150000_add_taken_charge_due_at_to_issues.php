<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Anche la scadenza di presa in carico viene registrata alla creazione,
     * come quella di risoluzione (sla_due_at): il giudizio su una fase già
     * conclusa non deve cambiare se la gravità viene riclassificata dopo.
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->timestampTz('taken_charge_due_at')->nullable();
        });

        // Stessa tabella dei tempi di App\Support\IssueSla
        DB::statement("
            UPDATE issues
            SET taken_charge_due_at = created_at + (
                CASE severity
                    WHEN 'critical' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'medium' THEN 5
                    ELSE 10
                END
            ) * interval '1 day'
            WHERE taken_charge_due_at IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropColumn('taken_charge_due_at');
        });
    }
};
