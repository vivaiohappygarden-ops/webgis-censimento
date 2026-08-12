<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Le segnalazioni nate prima dell'introduzione degli SLA ricevono la
     * scadenza di risoluzione calcolata dalla gravità (stessa tabella di
     * App\Support\IssueSla), contata dall'apertura.
     */
    public function up(): void
    {
        DB::statement("
            UPDATE issues
            SET sla_due_at = created_at + (
                CASE severity
                    WHEN 'critical' THEN 3
                    WHEN 'high' THEN 7
                    WHEN 'medium' THEN 15
                    ELSE 30
                END
            ) * interval '1 day'
            WHERE sla_due_at IS NULL
        ");
    }

    public function down(): void
    {
        // Nessun ritorno: il valore riempito è indistinguibile da uno impostato
    }
};
