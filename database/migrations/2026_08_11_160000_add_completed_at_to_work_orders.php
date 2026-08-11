<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- La rendicontazione per periodo ha bisogno del momento di chiusura:
            -- lo stato da solo non dice QUANDO il lavoro e' stato completato
            ALTER TABLE work_orders ADD COLUMN completed_at timestamptz;
            UPDATE work_orders SET completed_at = updated_at WHERE status = 'completed';
            CREATE INDEX ix_work_orders_tenant_completed
              ON work_orders (tenant_id, completed_at) WHERE deleted_at IS NULL AND completed_at IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS ix_work_orders_tenant_completed;
            ALTER TABLE work_orders DROP COLUMN IF EXISTS completed_at;
        SQL);
    }
};
