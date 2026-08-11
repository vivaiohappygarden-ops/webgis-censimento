<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Verbali di controllo qualità sui lavori: chi ha controllato,
            -- quando, con quale esito. L'ordine controllato non si riapre mai:
            -- un esito negativo genera una non conformità (ed eventualmente
            -- un ordine correttivo), la storia resta intatta
            CREATE TABLE work_checks (
              id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id           uuid NOT NULL REFERENCES organizations(id),
              work_order_id       uuid NOT NULL REFERENCES work_orders(id),
              checked_by          uuid REFERENCES users(id),
              checked_at          timestamptz NOT NULL DEFAULT now(),
              outcome             text NOT NULL CHECK (outcome IN ('passed','failed')),
              notes               text,
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX ix_work_checks_tenant_wo ON work_checks (tenant_id, work_order_id, checked_at DESC);

            -- Non conformità con flusso correttivo (GIS-DATA-MODEL §2.8)
            CREATE TABLE non_conformities (
              id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id           uuid NOT NULL REFERENCES organizations(id),
              code                text,
              origin              text NOT NULL CHECK (origin IN
                                  ('inspection','work_check','issue','internal_audit','client_claim','gis_validation')),
              origin_id           uuid,
              severity            text NOT NULL DEFAULT 'minor' CHECK (severity IN ('minor','major','critical')),
              status              text NOT NULL DEFAULT 'open'
                                  CHECK (status IN ('open','action','verified','closed')),
              asset_id            uuid REFERENCES assets(id),
              work_order_id       uuid REFERENCES work_orders(id),
              description         text NOT NULL,
              root_cause          text,
              corrective_action   text,
              responsible_id      uuid REFERENCES users(id),
              detected_on         date NOT NULL DEFAULT CURRENT_DATE,
              due_on              date,
              closed_at           timestamptz,
              created_by          uuid REFERENCES users(id),
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now(),
              deleted_at          timestamptz
            );
            CREATE UNIQUE INDEX uq_nc_tenant_code ON non_conformities (tenant_id, code)
              WHERE deleted_at IS NULL AND code IS NOT NULL;
            CREATE INDEX ix_nc_tenant_status ON non_conformities (tenant_id, status, due_on) WHERE deleted_at IS NULL;

            CREATE TRIGGER trg_work_checks_updated BEFORE UPDATE ON work_checks
              FOR EACH ROW EXECUTE FUNCTION fn_trg_set_updated_at();
            CREATE TRIGGER trg_nc_updated BEFORE UPDATE ON non_conformities
              FOR EACH ROW EXECUTE FUNCTION fn_trg_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS non_conformities;
            DROP TABLE IF EXISTS work_checks;
        SQL);
    }
};
