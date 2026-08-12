<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- I modelli di ispezione entrano nel working set della PWA:
            -- ogni modifica (anche alle domande) produce delta per i device
            CREATE TRIGGER trg_change_log_inspection_templates
              AFTER INSERT OR UPDATE OR DELETE ON inspection_templates
              FOR EACH ROW EXECUTE FUNCTION fn_trg_change_log('inspection_templates', 'id');
            CREATE TRIGGER trg_change_log_checklist_items
              AFTER INSERT OR UPDATE OR DELETE ON checklist_items
              FOR EACH ROW EXECUTE FUNCTION fn_trg_change_log('inspection_templates', 'template_id');

            -- Backfill: i modelli già esistenti devono raggiungere i device
            -- già inizializzati al prossimo pull
            INSERT INTO change_log (tenant_id, table_name, row_id)
            SELECT tenant_id, 'inspection_templates', id FROM inspection_templates WHERE deleted_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_change_log_checklist_items ON checklist_items;
            DROP TRIGGER IF EXISTS trg_change_log_inspection_templates ON inspection_templates;
        SQL);
    }
};
