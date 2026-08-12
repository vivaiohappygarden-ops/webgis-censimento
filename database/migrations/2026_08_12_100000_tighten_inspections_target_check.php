<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Il bersaglio è ESATTAMENTE uno: elemento O area, mai entrambi.
            -- La regola vive anche nel DB, non solo nel controller
            ALTER TABLE inspections DROP CONSTRAINT ck_inspections_target;
            ALTER TABLE inspections ADD CONSTRAINT ck_inspections_target
              CHECK ((asset_id IS NOT NULL) <> (area_id IS NOT NULL));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE inspections DROP CONSTRAINT ck_inspections_target;
            ALTER TABLE inspections ADD CONSTRAINT ck_inspections_target
              CHECK (asset_id IS NOT NULL OR area_id IS NOT NULL);
        SQL);
    }
};
