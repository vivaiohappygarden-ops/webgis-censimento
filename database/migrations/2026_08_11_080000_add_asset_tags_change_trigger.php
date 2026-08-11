<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Guardia sul row_id nullo (tag non ancora associato) e doppia
            -- registrazione sull'UPDATE: se il riferimento cambia (dissociazione,
            -- riassociazione) anche l'elemento che PERDE il tag riceve una riga
            -- di change log, altrimenti il suo working set non si aggiorna mai
            CREATE OR REPLACE FUNCTION fn_trg_change_log() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              v_tenant  uuid;
              v_row_old uuid;
              v_row_new uuid;
            BEGIN
              IF TG_OP IN ('UPDATE', 'DELETE') THEN
                v_row_old := (to_jsonb(OLD) ->> TG_ARGV[1])::uuid;
              END IF;
              IF TG_OP IN ('INSERT', 'UPDATE') THEN
                v_row_new := (to_jsonb(NEW) ->> TG_ARGV[1])::uuid;
              END IF;
              v_tenant := COALESCE(
                (to_jsonb(COALESCE(NEW, OLD)) ->> 'tenant_id')::uuid,
                NULL
              );

              IF v_row_old IS NOT NULL AND v_row_old IS DISTINCT FROM v_row_new THEN
                INSERT INTO change_log (tenant_id, table_name, row_id) VALUES (v_tenant, TG_ARGV[0], v_row_old);
              END IF;
              IF v_row_new IS NOT NULL THEN
                INSERT INTO change_log (tenant_id, table_name, row_id) VALUES (v_tenant, TG_ARGV[0], v_row_new);
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
