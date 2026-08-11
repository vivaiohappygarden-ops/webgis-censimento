<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Backfill: gli ordini creati prima dei trigger di change log devono
            -- comunque raggiungere i device gia' inizializzati al prossimo pull
            INSERT INTO change_log (tenant_id, table_name, row_id)
            SELECT tenant_id, 'work_orders', id FROM work_orders WHERE deleted_at IS NULL;

            -- Entrare o uscire da una squadra cambia quali ordini l'operatore
            -- vede dal campo: si rimettono nel delta tutti gli ordini della
            -- squadra toccata (upsert per chi entra, tombstone per chi esce)
            CREATE OR REPLACE FUNCTION fn_trg_team_membership_change_log() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              INSERT INTO change_log (tenant_id, table_name, row_id)
              SELECT w.tenant_id, 'work_orders', w.id
              FROM work_orders w
              WHERE w.team_id = COALESCE(NEW.team_id, OLD.team_id)
                AND w.deleted_at IS NULL;
              RETURN COALESCE(NEW, OLD);
            END $$;

            CREATE TRIGGER trg_change_log_team_members
              AFTER INSERT OR UPDATE OR DELETE ON team_members
              FOR EACH ROW EXECUTE FUNCTION fn_trg_team_membership_change_log();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_change_log_team_members ON team_members;
            DROP FUNCTION IF EXISTS fn_trg_team_membership_change_log();
        SQL);
    }
};
