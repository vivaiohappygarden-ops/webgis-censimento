<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE trees (
              asset_id               uuid PRIMARY KEY REFERENCES assets(id) ON DELETE CASCADE,
              tenant_id              uuid NOT NULL REFERENCES organizations(id),
              plant_number           text,
              genus                  text,
              species                text,
              cultivar               text,
              family                 text,
              common_name            text,
              height_m               numeric(5,2),
              dbh_cm                 numeric(6,1),
              trunk_circumference_cm numeric(7,1),
              trunk_count            smallint NOT NULL DEFAULT 1,
              crown_diameter_m       numeric(5,2),
              crown_insertion_m      numeric(5,2),
              age_years_est          integer,
              age_class              text,
              vegetative_state       text,
              is_monumental          boolean NOT NULL DEFAULT false,
              monumental_ref         text,
              is_protected           boolean NOT NULL DEFAULT false,
              protection_ref         text,
              is_dedicated           boolean NOT NULL DEFAULT false,
              dedicated_to           jsonb NOT NULL DEFAULT '{}'::jsonb,
              has_stake              boolean NOT NULL DEFAULT false,
              has_bracing            boolean NOT NULL DEFAULT false,
              bracing_notes          text,
              planted_on             date,
              removed_on             date,
              removal_reason         text,
              created_at             timestamptz NOT NULL DEFAULT now(),
              updated_at             timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX ix_trees_tenant_species ON trees (tenant_id, genus, species);
            CREATE INDEX gin_trees_species_trgm ON trees USING gin (species gin_trgm_ops);

            CREATE TABLE tree_assessments (
              id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id         uuid NOT NULL REFERENCES organizations(id),
              tree_id           uuid NOT NULL REFERENCES trees(asset_id) ON DELETE CASCADE,
              assessment_type   text NOT NULL CHECK (assessment_type IN
                                ('vta_visual','vta_instrumental','vsa','pull_test','aerial_inspection','other')),
              assessed_on       date NOT NULL,
              assessor_id       uuid REFERENCES users(id),
              assessor_external text,
              defects           jsonb NOT NULL DEFAULT '{}'::jsonb,
              targets           jsonb NOT NULL DEFAULT '{}'::jsonb,
              failure_class     text CHECK (failure_class IS NULL OR failure_class IN ('A','B','C','C/D','D')),
              outcome           text CHECK (outcome IN ('ok','monitor','prescriptions','fell')),
              prescriptions     text,
              next_check_due    date,
              version           integer NOT NULL DEFAULT 1,
              created_by        uuid REFERENCES users(id),
              updated_by        uuid REFERENCES users(id),
              created_at        timestamptz NOT NULL DEFAULT now(),
              updated_at        timestamptz NOT NULL DEFAULT now(),
              deleted_at        timestamptz
            );
            CREATE INDEX ix_tree_assessments_tenant_tree ON tree_assessments (tenant_id, tree_id, assessed_on DESC)
              WHERE deleted_at IS NULL;
            CREATE INDEX ix_tree_assessments_due ON tree_assessments (tenant_id, next_check_due)
              WHERE deleted_at IS NULL AND next_check_due IS NOT NULL;
            CREATE INDEX gin_tree_assessments_defects ON tree_assessments USING gin (defects jsonb_path_ops);

            CREATE TABLE instrumental_analyses (
              id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id             uuid NOT NULL REFERENCES organizations(id),
              assessment_id         uuid NOT NULL REFERENCES tree_assessments(id) ON DELETE CASCADE,
              instrument_type       text NOT NULL CHECK (instrument_type IN
                                    ('resistograph','sonic_tomograph','electric_tomograph','pull_test','dendro_densimeter','other')),
              instrument_model      text,
              measured_at           timestamptz,
              measurement_height_cm integer,
              measures              jsonb NOT NULL DEFAULT '{}'::jsonb,
              document_id           uuid REFERENCES documents(id),
              notes                 text,
              created_at            timestamptz NOT NULL DEFAULT now(),
              updated_at            timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX ix_instrumental_tenant_assessment ON instrumental_analyses (tenant_id, assessment_id);

            CREATE TABLE planting_sites (
              asset_id         uuid PRIMARY KEY REFERENCES assets(id) ON DELETE CASCADE,
              tenant_id        uuid NOT NULL REFERENCES organizations(id),
              status           text NOT NULL DEFAULT 'free'
                               CHECK (status IN ('free','reserved','planted','unusable')),
              planned_species  text,
              origin           text CHECK (origin IS NULL OR origin IN ('felling','new_design','transplant','other')),
              previous_tree_id uuid REFERENCES trees(asset_id),
              target_season    text,
              notes            text,
              created_at       timestamptz NOT NULL DEFAULT now(),
              updated_at       timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX ix_planting_sites_tenant_status ON planting_sites (tenant_id, status);

            -- Backfill: gli asset già censiti con tipi che richiedono la scheda
            -- albero (es. P103108) ricevono il record trees mancante.
            INSERT INTO trees (asset_id, tenant_id)
            SELECT a.id, a.tenant_id
            FROM assets a
            JOIN catalog_object_types t ON t.id = a.object_type_id
            WHERE t.requires_tree_record
              AND NOT EXISTS (SELECT 1 FROM trees tr WHERE tr.asset_id = a.id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS planting_sites;
            DROP TABLE IF EXISTS instrumental_analyses;
            DROP TABLE IF EXISTS tree_assessments;
            DROP TABLE IF EXISTS trees;
        SQL);
    }
};
