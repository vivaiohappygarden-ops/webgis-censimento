<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Guardia sul row_id nullo: un tag non ancora associato (asset_id NULL)
            -- non produce righe di change log finché non punta a un elemento
            CREATE OR REPLACE FUNCTION fn_trg_change_log() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              v_tenant uuid;
              v_row_id uuid;
            BEGIN
              IF TG_OP = 'DELETE' THEN
                v_tenant := OLD.tenant_id;
                v_row_id := (to_jsonb(OLD) ->> TG_ARGV[1])::uuid;
              ELSE
                v_tenant := NEW.tenant_id;
                v_row_id := (to_jsonb(NEW) ->> TG_ARGV[1])::uuid;
              END IF;
              IF v_row_id IS NOT NULL THEN
                INSERT INTO change_log (tenant_id, table_name, row_id) VALUES (v_tenant, TG_ARGV[0], v_row_id);
              END IF;
              RETURN COALESCE(NEW, OLD);
            END $$;

            -- I tag viaggiano dentro la riga asset del working set: il log punta all'asset
            CREATE TRIGGER trg_change_log_asset_tags
              AFTER INSERT OR UPDATE OR DELETE ON asset_tags
              FOR EACH ROW EXECUTE FUNCTION fn_trg_change_log('assets', 'asset_id');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_change_log_asset_tags ON asset_tags;
        SQL);
    }
};
