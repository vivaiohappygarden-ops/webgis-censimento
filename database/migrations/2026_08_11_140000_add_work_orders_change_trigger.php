<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Gli ordini di lavoro entrano nel working set della PWA operatore:
            -- ogni modifica (anche agli elementi collegati) produce delta
            CREATE TRIGGER trg_change_log_work_orders
              AFTER INSERT OR UPDATE OR DELETE ON work_orders
              FOR EACH ROW EXECUTE FUNCTION fn_trg_change_log('work_orders', 'id');
            CREATE TRIGGER trg_change_log_wo_assets
              AFTER INSERT OR UPDATE OR DELETE ON work_order_assets
              FOR EACH ROW EXECUTE FUNCTION fn_trg_change_log('work_orders', 'work_order_id');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_change_log_wo_assets ON work_order_assets;
            DROP TRIGGER IF EXISTS trg_change_log_work_orders ON work_orders;
        SQL);
    }
};
