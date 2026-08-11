<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Lavorazioni (sfalcio, potatura, abbattimento, ...)
            CREATE TABLE work_types (
              id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id           uuid NOT NULL REFERENCES organizations(id),
              code                text NOT NULL,
              name                text NOT NULL,
              category            text,
              unit                text NOT NULL,
              std_duration_min    integer,
              default_frequency   jsonb NOT NULL DEFAULT '{}'::jsonb,
              applicable_geometry char(1) CHECK (applicable_geometry IN ('P','L','S')),
              requires_photos     boolean NOT NULL DEFAULT false,
              is_active           boolean NOT NULL DEFAULT true,
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now(),
              deleted_at          timestamptz
            );
            CREATE UNIQUE INDEX uq_work_types_tenant_code ON work_types (tenant_id, code) WHERE deleted_at IS NULL;

            -- Listini prezzi (struttura pronta; gestione completa in un blocco successivo)
            CREATE TABLE price_lists (
              id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id           uuid NOT NULL REFERENCES organizations(id),
              code                text NOT NULL,
              name                text NOT NULL,
              source              text,
              year                integer,
              currency            char(3) NOT NULL DEFAULT 'EUR',
              valid_from          date,
              valid_to            date,
              is_active           boolean NOT NULL DEFAULT true,
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now(),
              deleted_at          timestamptz
            );
            CREATE UNIQUE INDEX uq_price_lists_tenant_code ON price_lists (tenant_id, code) WHERE deleted_at IS NULL;

            CREATE TABLE price_list_items (
              id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id           uuid NOT NULL REFERENCES organizations(id),
              price_list_id       uuid NOT NULL REFERENCES price_lists(id) ON DELETE CASCADE,
              work_type_id        uuid NOT NULL REFERENCES work_types(id),
              item_code           text,
              description         text,
              unit                text NOT NULL,
              unit_price          numeric(12,4) NOT NULL,
              overhead_pct        numeric(5,2),
              safety_cost         numeric(12,4),
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now(),
              deleted_at          timestamptz
            );
            CREATE UNIQUE INDEX uq_price_list_items
              ON price_list_items (tenant_id, price_list_id, work_type_id, COALESCE(item_code,''))
              WHERE deleted_at IS NULL;

            -- Squadre operative
            CREATE TABLE teams (
              id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id           uuid NOT NULL REFERENCES organizations(id),
              code                text,
              name                text NOT NULL,
              leader_id           uuid REFERENCES users(id),
              is_active           boolean NOT NULL DEFAULT true,
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now(),
              deleted_at          timestamptz
            );
            CREATE UNIQUE INDEX uq_teams_tenant_code ON teams (tenant_id, code) WHERE deleted_at IS NULL AND code IS NOT NULL;

            CREATE TABLE team_members (
              team_id             uuid NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
              user_id             uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              tenant_id           uuid NOT NULL REFERENCES organizations(id),
              member_role         text NOT NULL DEFAULT 'operator' CHECK (member_role IN ('leader','operator','driver','external')),
              joined_on           date NOT NULL DEFAULT CURRENT_DATE,
              left_on             date,
              PRIMARY KEY (team_id, user_id)
            );
            CREATE INDEX ix_team_members_user ON team_members (tenant_id, user_id);

            -- Ordini di lavoro
            CREATE TABLE work_orders (
              id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id           uuid NOT NULL REFERENCES organizations(id),
              code                text NOT NULL,
              client_id           uuid REFERENCES clients(id),
              contract_id         uuid REFERENCES contracts(id),
              site_id             uuid REFERENCES sites(id),
              area_id             uuid REFERENCES areas(id),
              work_type_id        uuid REFERENCES work_types(id),
              title               text NOT NULL,
              description         text,
              status              text NOT NULL DEFAULT 'draft',
              priority            text NOT NULL DEFAULT 'normal' CHECK (priority IN ('low','normal','high','urgent')),
              origin              text NOT NULL DEFAULT 'manual' CHECK (origin IN
                                  ('manual','maintenance_plan','issue','non_conformity','inspection','estimate','client_request')),
              origin_id           uuid,
              planned_start       date,
              planned_end         date,
              due_at              timestamptz,
              estimated_duration_min integer,
              team_id             uuid REFERENCES teams(id),
              assigned_to         uuid REFERENCES users(id),
              price_list_id       uuid REFERENCES price_lists(id),
              risks               text,
              ppe                 jsonb NOT NULL DEFAULT '[]'::jsonb,
              version             integer NOT NULL DEFAULT 1,
              created_by          uuid REFERENCES users(id),
              updated_by          uuid REFERENCES users(id),
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now(),
              deleted_at          timestamptz
            );
            CREATE UNIQUE INDEX uq_work_orders_tenant_code ON work_orders (tenant_id, code) WHERE deleted_at IS NULL;
            CREATE INDEX ix_work_orders_tenant_status ON work_orders (tenant_id, status, planned_start) WHERE deleted_at IS NULL;
            CREATE INDEX ix_work_orders_tenant_team ON work_orders (tenant_id, team_id) WHERE deleted_at IS NULL;
            CREATE INDEX ix_work_orders_tenant_contract ON work_orders (tenant_id, contract_id) WHERE deleted_at IS NULL;

            -- Oggetti dell'ODL (N:M con quantità)
            CREATE TABLE work_order_assets (
              id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id           uuid NOT NULL REFERENCES organizations(id),
              work_order_id       uuid NOT NULL REFERENCES work_orders(id) ON DELETE CASCADE,
              asset_id            uuid NOT NULL REFERENCES assets(id),
              work_type_id        uuid REFERENCES work_types(id),
              planned_quantity    numeric(12,2),
              unit                text,
              status              text NOT NULL DEFAULT 'todo' CHECK (status IN ('todo','in_progress','done','skipped')),
              notes               text,
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_wo_assets UNIQUE NULLS NOT DISTINCT (work_order_id, asset_id, work_type_id)
            );
            CREATE INDEX ix_wo_assets_tenant_asset ON work_order_assets (tenant_id, asset_id);

            -- Consuntivi di campo (la UI arriva nel blocco consuntivazione)
            CREATE TABLE work_logs (
              id                  uuid PRIMARY KEY,
              tenant_id           uuid NOT NULL REFERENCES organizations(id),
              work_order_id       uuid NOT NULL REFERENCES work_orders(id),
              asset_id            uuid REFERENCES assets(id),
              team_id             uuid REFERENCES teams(id),
              operator_id         uuid REFERENCES users(id),
              started_at          timestamptz NOT NULL,
              start_geom          geometry(Point,4326),
              ended_at            timestamptz,
              end_geom            geometry(Point,4326),
              man_hours           numeric(6,2),
              quantity            numeric(12,2),
              unit                text,
              vehicles            jsonb NOT NULL DEFAULT '[]'::jsonb,
              equipment           jsonb NOT NULL DEFAULT '[]'::jsonb,
              materials           jsonb NOT NULL DEFAULT '[]'::jsonb,
              notes               text,
              operator_signature_s3_key text,
              client_signature_s3_key   text,
              version             integer NOT NULL DEFAULT 1,
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now(),
              deleted_at          timestamptz,
              CONSTRAINT ck_work_logs_times CHECK (ended_at IS NULL OR ended_at >= started_at)
            );
            CREATE INDEX ix_work_logs_tenant_wo ON work_logs (tenant_id, work_order_id) WHERE deleted_at IS NULL;
            CREATE INDEX ix_work_logs_tenant_started ON work_logs (tenant_id, started_at DESC) WHERE deleted_at IS NULL;
            CREATE INDEX gist_work_logs_start_geom ON work_logs USING gist (start_geom);

            -- updated_at automatico e change log dove serve
            CREATE TRIGGER trg_work_types_updated BEFORE UPDATE ON work_types
              FOR EACH ROW EXECUTE FUNCTION fn_trg_set_updated_at();
            CREATE TRIGGER trg_teams_updated BEFORE UPDATE ON teams
              FOR EACH ROW EXECUTE FUNCTION fn_trg_set_updated_at();
            CREATE TRIGGER trg_work_orders_updated BEFORE UPDATE ON work_orders
              FOR EACH ROW EXECUTE FUNCTION fn_trg_set_updated_at();
            CREATE TRIGGER trg_wo_assets_updated BEFORE UPDATE ON work_order_assets
              FOR EACH ROW EXECUTE FUNCTION fn_trg_set_updated_at();
            CREATE TRIGGER trg_work_logs_updated BEFORE UPDATE ON work_logs
              FOR EACH ROW EXECUTE FUNCTION fn_trg_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS work_logs;
            DROP TABLE IF EXISTS work_order_assets;
            DROP TABLE IF EXISTS work_orders;
            DROP TABLE IF EXISTS team_members;
            DROP TABLE IF EXISTS teams;
            DROP TABLE IF EXISTS contract_price_lists;
            DROP TABLE IF EXISTS price_list_items;
            DROP TABLE IF EXISTS price_lists;
            DROP TABLE IF EXISTS work_types;
        SQL);
    }
};
