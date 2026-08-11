<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Registro idempotenza push: un comando (tenant, idempotency_key) si applica una
            -- volta sola; il replay restituisce l'esito originale memorizzato (OFFLINE-SYNC §1.3)
            CREATE TABLE sync_operations (
              id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id       uuid NOT NULL REFERENCES organizations(id),
              idempotency_key uuid NOT NULL,
              batch_id        uuid,
              device_id       text,
              user_id         uuid REFERENCES users(id),
              command_type    text NOT NULL,
              entity_id       uuid,
              status          text NOT NULL CHECK (status IN ('applied','conflict','rejected')),
              result          jsonb NOT NULL DEFAULT '{}'::jsonb,
              created_at      timestamptz NOT NULL DEFAULT now(),
              UNIQUE (tenant_id, idempotency_key)
            );
            CREATE INDEX ix_sync_operations_entity ON sync_operations (tenant_id, entity_id);

            -- Change log per il pull delta: cursore = sequenza server-side, non timestamp
            -- (OFFLINE-SYNC §5.3). La riga registra SOLO l'avvenuto cambiamento; lo stato
            -- corrente si legge dalle tabelle al momento del pull.
            CREATE TABLE change_log (
              id         bigserial PRIMARY KEY,
              tenant_id  uuid NOT NULL,
              table_name text NOT NULL,
              row_id     uuid NOT NULL,
              -- Id transazione: il pull serve solo righe di transazioni già concluse
              -- (txid < xmin dello snapshot corrente), così una transazione lunga
              -- con id bassi non viene scavalcata dal cursore e persa per sempre
              txid       xid8 NOT NULL DEFAULT pg_current_xact_id(),
              created_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX ix_change_log_tenant ON change_log (tenant_id, id);

            CREATE OR REPLACE FUNCTION fn_trg_change_log() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              v_tenant uuid;
              v_row_id uuid;
            BEGIN
              -- Accesso generico via jsonb: la stessa funzione serve tabelle con
              -- PK "id" e specializzazioni con PK "asset_id" (TG_ARGV[1])
              IF TG_OP = 'DELETE' THEN
                v_tenant := OLD.tenant_id;
                v_row_id := (to_jsonb(OLD) ->> TG_ARGV[1])::uuid;
              ELSE
                v_tenant := NEW.tenant_id;
                v_row_id := (to_jsonb(NEW) ->> TG_ARGV[1])::uuid;
              END IF;
              INSERT INTO change_log (tenant_id, table_name, row_id) VALUES (v_tenant, TG_ARGV[0], v_row_id);
              RETURN COALESCE(NEW, OLD);
            END $$;

            CREATE TRIGGER trg_change_log_areas
              AFTER INSERT OR UPDATE OR DELETE ON areas
              FOR EACH ROW EXECUTE FUNCTION fn_trg_change_log('areas', 'id');
            CREATE TRIGGER trg_change_log_assets
              AFTER INSERT OR UPDATE OR DELETE ON assets
              FOR EACH ROW EXECUTE FUNCTION fn_trg_change_log('assets', 'id');
            -- Le specializzazioni viaggiano dentro la riga asset: il log punta all'asset
            CREATE TRIGGER trg_change_log_trees
              AFTER INSERT OR UPDATE OR DELETE ON trees
              FOR EACH ROW EXECUTE FUNCTION fn_trg_change_log('assets', 'asset_id');
            CREATE TRIGGER trg_change_log_planting_sites
              AFTER INSERT OR UPDATE OR DELETE ON planting_sites
              FOR EACH ROW EXECUTE FUNCTION fn_trg_change_log('assets', 'asset_id');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_change_log_planting_sites ON planting_sites;
            DROP TRIGGER IF EXISTS trg_change_log_trees ON trees;
            DROP TRIGGER IF EXISTS trg_change_log_assets ON assets;
            DROP TRIGGER IF EXISTS trg_change_log_areas ON areas;
            DROP FUNCTION IF EXISTS fn_trg_change_log();
            DROP TABLE IF EXISTS change_log;
            DROP TABLE IF EXISTS sync_operations;
        SQL);
    }
};
