<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Protocollo della perizia: numero e data di emissione fissati alla prima
 * stampa e mai più ricalcolati. Senza, ogni download avrebbe una data
 * diversa e il numero cambierebbe: due copie della stessa perizia
 * risulterebbero due documenti diversi.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tree_assessments ADD COLUMN report_number text');
        DB::statement('ALTER TABLE tree_assessments ADD COLUMN report_issued_at timestamptz');
        DB::statement(
            'CREATE UNIQUE INDEX uq_tree_assessments_report_number
             ON tree_assessments (tenant_id, report_number) WHERE report_number IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_tree_assessments_report_number');
        DB::statement('ALTER TABLE tree_assessments DROP COLUMN report_issued_at');
        DB::statement('ALTER TABLE tree_assessments DROP COLUMN report_number');
    }
};
