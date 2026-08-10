<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE clients (
              id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id    uuid NOT NULL REFERENCES organizations(id),
              code         text,
              name         text NOT NULL,
              client_type  text NOT NULL DEFAULT 'private'
                           CHECK (client_type IN ('public','private','condo','other')),
              vat_number   text,
              fiscal_code  text,
              sdi_code     text,
              pec          citext,
              ipa_code     text,
              address      jsonb NOT NULL DEFAULT '{}'::jsonb,
              contacts     jsonb NOT NULL DEFAULT '{}'::jsonb,
              notes        text,
              is_active    boolean NOT NULL DEFAULT true,
              created_at   timestamptz NOT NULL DEFAULT now(),
              updated_at   timestamptz NOT NULL DEFAULT now(),
              deleted_at   timestamptz
            );
            CREATE UNIQUE INDEX uq_clients_tenant_code ON clients (tenant_id, code) WHERE deleted_at IS NULL AND code IS NOT NULL;
            CREATE INDEX ix_clients_tenant_name ON clients (tenant_id, name) WHERE deleted_at IS NULL;

            CREATE TABLE contracts (
              id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id     uuid NOT NULL REFERENCES organizations(id),
              client_id     uuid NOT NULL REFERENCES clients(id),
              code          text NOT NULL,
              title         text NOT NULL,
              contract_type text CHECK (contract_type IN
                            ('appalto','global_service','manutenzione','censimento','pronto_intervento','altro')),
              cig           text,
              cup           text,
              starts_on     date,
              ends_on       date,
              amount        numeric(14,2),
              currency      char(3) NOT NULL DEFAULT 'EUR',
              status        text NOT NULL DEFAULT 'draft',
              sla           jsonb NOT NULL DEFAULT '{}'::jsonb,
              notes         text,
              created_at    timestamptz NOT NULL DEFAULT now(),
              updated_at    timestamptz NOT NULL DEFAULT now(),
              deleted_at    timestamptz,
              CONSTRAINT ck_contracts_dates CHECK (ends_on IS NULL OR starts_on IS NULL OR ends_on >= starts_on)
            );
            CREATE UNIQUE INDEX uq_contracts_tenant_code ON contracts (tenant_id, code) WHERE deleted_at IS NULL;
            CREATE INDEX ix_contracts_tenant_client ON contracts (tenant_id, client_id) WHERE deleted_at IS NULL;

            CREATE TABLE sites (
              id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id    uuid NOT NULL REFERENCES organizations(id),
              client_id    uuid NOT NULL REFERENCES clients(id),
              code         text,
              name         text NOT NULL,
              istat_code   varchar(6),
              municipality text,
              province     char(2),
              region       text,
              geom         geometry(MultiPolygon,4326),
              created_at   timestamptz NOT NULL DEFAULT now(),
              updated_at   timestamptz NOT NULL DEFAULT now(),
              deleted_at   timestamptz
            );
            CREATE UNIQUE INDEX uq_sites_tenant_code ON sites (tenant_id, code) WHERE deleted_at IS NULL AND code IS NOT NULL;
            CREATE INDEX ix_sites_tenant_client ON sites (tenant_id, client_id) WHERE deleted_at IS NULL;
            CREATE INDEX gist_sites_geom ON sites USING gist (geom);

            CREATE TABLE localities (
              id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id        uuid NOT NULL REFERENCES organizations(id),
              site_id          uuid NOT NULL REFERENCES sites(id),
              code             text,
              name             text NOT NULL,
              survey_zone_code text,
              geom             geometry(MultiPolygon,4326),
              created_at       timestamptz NOT NULL DEFAULT now(),
              updated_at       timestamptz NOT NULL DEFAULT now(),
              deleted_at       timestamptz
            );
            CREATE UNIQUE INDEX uq_localities_site_code ON localities (tenant_id, site_id, code) WHERE deleted_at IS NULL AND code IS NOT NULL;
            CREATE INDEX ix_localities_tenant_site ON localities (tenant_id, site_id) WHERE deleted_at IS NULL;
            CREATE INDEX gist_localities_geom ON localities USING gist (geom);

            CREATE TABLE areas (
              id                   uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id            uuid NOT NULL REFERENCES organizations(id),
              locality_id          uuid NOT NULL REFERENCES localities(id),
              parent_id            uuid REFERENCES areas(id),
              code                 text,
              name                 text NOT NULL,
              area_type            text NOT NULL DEFAULT 'management'
                                   CHECK (area_type IN ('management','functional','temporary')),
              status               text NOT NULL DEFAULT 'active'
                                   CHECK (status IN ('planned','active','suspended','dismissed')),
              manager              text,
              street_code          text,
              geom                 geometry(MultiPolygon,4326) NOT NULL,
              computed_area_sqm    numeric(14,2) GENERATED ALWAYS AS
                                   (round(ST_Area(ST_Transform(geom, 7791))::numeric, 2)) STORED,
              computed_perimeter_m numeric(14,2) GENERATED ALWAYS AS
                                   (round(ST_Perimeter(ST_Transform(geom, 7791))::numeric, 2)) STORED,
              valid_from           date NOT NULL DEFAULT CURRENT_DATE,
              valid_to             date,
              notes                text,
              created_by           uuid REFERENCES users(id),
              updated_by           uuid REFERENCES users(id),
              created_at           timestamptz NOT NULL DEFAULT now(),
              updated_at           timestamptz NOT NULL DEFAULT now(),
              deleted_at           timestamptz,
              CONSTRAINT ck_areas_not_self_parent CHECK (parent_id IS DISTINCT FROM id),
              CONSTRAINT ck_areas_valid_dates CHECK (valid_to IS NULL OR valid_to >= valid_from),
              CONSTRAINT ck_areas_geom_not_empty CHECK (NOT ST_IsEmpty(geom))
            );
            CREATE UNIQUE INDEX uq_areas_tenant_code ON areas (tenant_id, code) WHERE deleted_at IS NULL AND code IS NOT NULL;
            CREATE INDEX ix_areas_tenant_locality ON areas (tenant_id, locality_id) WHERE deleted_at IS NULL;
            CREATE INDEX ix_areas_parent ON areas (parent_id) WHERE deleted_at IS NULL;
            CREATE INDEX gist_areas_geom ON areas USING gist (geom);

            -- Anti-ciclo sulla gerarchia delle aree (oltre il self-reference).
            -- L'advisory lock per tenant serializza le modifiche concorrenti alla
            -- gerarchia: senza, due transazioni potrebbero creare un ciclo che il
            -- walk in READ COMMITTED non vede.
            CREATE OR REPLACE FUNCTION fn_trg_areas_no_cycle() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              current_parent uuid;
              depth integer := 0;
            BEGIN
              PERFORM pg_advisory_xact_lock(hashtextextended('areas_hierarchy:' || NEW.tenant_id::text, 0));
              current_parent := NEW.parent_id;
              WHILE current_parent IS NOT NULL AND depth < 100 LOOP
                IF current_parent = NEW.id THEN
                  RAISE EXCEPTION 'Ciclo nella gerarchia delle aree (area %)', NEW.id;
                END IF;
                SELECT parent_id INTO current_parent FROM areas WHERE id = current_parent;
                depth := depth + 1;
              END LOOP;
              RETURN NEW;
            END $$;

            CREATE TRIGGER trg_areas_no_cycle
              BEFORE INSERT OR UPDATE OF parent_id ON areas
              FOR EACH ROW WHEN (NEW.parent_id IS NOT NULL)
              EXECUTE FUNCTION fn_trg_areas_no_cycle();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS areas; DROP FUNCTION IF EXISTS fn_trg_areas_no_cycle();');
        Schema::dropIfExists('localities');
        Schema::dropIfExists('sites');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('clients');
    }
};
