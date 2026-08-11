<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Segnalazioni (GIS-DATA-MODEL §2.8): interne o dei clienti, con
            -- presa in carico, risoluzione e ordine di lavoro generato
            CREATE TABLE issues (
              id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id           uuid NOT NULL REFERENCES organizations(id),
              code                text,
              reporter_type       text NOT NULL DEFAULT 'internal'
                                  CHECK (reporter_type IN ('internal','client','citizen','system')),
              reporter_user_id    uuid REFERENCES users(id),
              reporter_name       text,
              reporter_contact    text,
              channel             text NOT NULL DEFAULT 'backoffice' CHECK (channel IN
                                  ('backoffice','pwa','client_portal','public_portal','qr_public','email','phone','api')),
              category            text,
              severity            text NOT NULL DEFAULT 'medium' CHECK (severity IN ('low','medium','high','critical')),
              status              text NOT NULL DEFAULT 'open'
                                  CHECK (status IN ('open','in_charge','resolved','dismissed')),
              asset_id            uuid REFERENCES assets(id),
              area_id             uuid REFERENCES areas(id),
              geom                geometry(Point,4326),
              description         text NOT NULL,
              sla_due_at          timestamptz,
              taken_charge_at     timestamptz,
              resolved_at         timestamptz,
              resolution_notes    text,
              work_order_id       uuid REFERENCES work_orders(id),
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now(),
              deleted_at          timestamptz
            );
            CREATE UNIQUE INDEX uq_issues_tenant_code ON issues (tenant_id, code)
              WHERE deleted_at IS NULL AND code IS NOT NULL;
            CREATE INDEX ix_issues_tenant_status ON issues (tenant_id, status, sla_due_at) WHERE deleted_at IS NULL;
            CREATE INDEX ix_issues_tenant_asset ON issues (tenant_id, asset_id) WHERE deleted_at IS NULL;
            CREATE INDEX gist_issues_geom ON issues USING gist (geom);

            CREATE TRIGGER trg_issues_updated BEFORE UPDATE ON issues
              FOR EACH ROW EXECUTE FUNCTION fn_trg_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS issues;');
    }
};
