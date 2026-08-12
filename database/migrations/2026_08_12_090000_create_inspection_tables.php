<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Modelli di ispezione (GIS-DATA-MODEL §2.6), es. UNI EN 1176-7 aree gioco
            CREATE TABLE inspection_templates (
              id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id           uuid NOT NULL REFERENCES organizations(id),
              code                text,
              name                text NOT NULL,
              target              text NOT NULL DEFAULT 'asset' CHECK (target IN ('asset','area')),
              object_type_id      uuid REFERENCES catalog_object_types(id),
              standard_ref        text,
              frequency_days      integer,
              version             integer NOT NULL DEFAULT 1,
              is_active           boolean NOT NULL DEFAULT true,
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now(),
              deleted_at          timestamptz
            );
            CREATE UNIQUE INDEX uq_inspection_templates_code
              ON inspection_templates (tenant_id, code) WHERE deleted_at IS NULL AND code IS NOT NULL;

            -- Domande del modello
            CREATE TABLE checklist_items (
              id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id           uuid NOT NULL REFERENCES organizations(id),
              template_id         uuid NOT NULL REFERENCES inspection_templates(id) ON DELETE CASCADE,
              sort_order          integer NOT NULL DEFAULT 0,
              question            text NOT NULL,
              answer_type         text NOT NULL DEFAULT 'ok_ko' CHECK (answer_type IN
                                  ('ok_ko','ok_ko_na','text','number','select','photo')),
              options             jsonb NOT NULL DEFAULT '[]'::jsonb,
              ko_creates_nc       boolean NOT NULL DEFAULT true,
              photo_required_on_ko boolean NOT NULL DEFAULT false,
              attachment_required boolean NOT NULL DEFAULT false,
              weight              integer NOT NULL DEFAULT 1,
              visibility_condition jsonb NOT NULL DEFAULT '{}'::jsonb,
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX ix_checklist_items_template ON checklist_items (template_id, sort_order);

            -- Esecuzioni: la versione del modello resta congelata nell'ispezione
            -- e le risposte fotografano anche il testo della domanda
            CREATE TABLE inspections (
              id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id           uuid NOT NULL REFERENCES organizations(id),
              template_id         uuid NOT NULL REFERENCES inspection_templates(id),
              template_version    integer NOT NULL,
              asset_id            uuid REFERENCES assets(id),
              area_id             uuid REFERENCES areas(id),
              inspector_id        uuid NOT NULL REFERENCES users(id),
              started_at          timestamptz,
              completed_at        timestamptz,
              geom                geometry(Point,4326),
              answers             jsonb NOT NULL DEFAULT '{}'::jsonb,
              outcome             text CHECK (outcome IN ('passed','passed_with_remarks','failed','not_completed')),
              signature_s3_key    text,
              version             integer NOT NULL DEFAULT 1,
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now(),
              deleted_at          timestamptz,
              CONSTRAINT ck_inspections_target CHECK (asset_id IS NOT NULL OR area_id IS NOT NULL)
            );
            CREATE INDEX ix_inspections_tenant_asset ON inspections (tenant_id, asset_id, completed_at DESC)
              WHERE deleted_at IS NULL;
            CREATE INDEX ix_inspections_tenant_area ON inspections (tenant_id, area_id, completed_at DESC)
              WHERE deleted_at IS NULL;
            CREATE INDEX gin_inspections_answers ON inspections USING gin (answers jsonb_path_ops);

            CREATE TRIGGER trg_inspection_templates_updated BEFORE UPDATE ON inspection_templates
              FOR EACH ROW EXECUTE FUNCTION fn_trg_set_updated_at();
            CREATE TRIGGER trg_checklist_items_updated BEFORE UPDATE ON checklist_items
              FOR EACH ROW EXECUTE FUNCTION fn_trg_set_updated_at();
            CREATE TRIGGER trg_inspections_updated BEFORE UPDATE ON inspections
              FOR EACH ROW EXECUTE FUNCTION fn_trg_set_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS inspections;
            DROP TABLE IF EXISTS checklist_items;
            DROP TABLE IF EXISTS inspection_templates;
        SQL);
    }
};
