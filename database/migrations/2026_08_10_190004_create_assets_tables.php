<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE assets (
              id                   uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id            uuid NOT NULL REFERENCES organizations(id),
              area_id              uuid NOT NULL REFERENCES areas(id),
              object_type_id       uuid NOT NULL REFERENCES catalog_object_types(id),
              census_code          text,
              status               text NOT NULL DEFAULT 'active',
              geom                 geometry(Geometry,4326) NOT NULL,
              gps_accuracy_m       numeric(6,2),
              survey_method        text CHECK (survey_method IN
                                   ('gps','gps_rtk','digitized','cad_import','shapefile_import','manual_map','estimated')),
              surveyed_at          date,
              surveyed_by          uuid REFERENCES users(id),
              valid_from           date NOT NULL DEFAULT CURRENT_DATE,
              valid_to             date,
              attributes           jsonb NOT NULL DEFAULT '{}'::jsonb,
              notes                text,
              computed_area_sqm    numeric(14,2) GENERATED ALWAYS AS (
                                     CASE WHEN GeometryType(geom) IN ('POLYGON','MULTIPOLYGON')
                                          THEN round(ST_Area(ST_Transform(geom, 7791))::numeric, 2) END) STORED,
              computed_length_m    numeric(14,2) GENERATED ALWAYS AS (
                                     CASE WHEN GeometryType(geom) IN ('LINESTRING','MULTILINESTRING')
                                          THEN round(ST_Length(ST_Transform(geom, 7791))::numeric, 2) END) STORED,
              computed_perimeter_m numeric(14,2) GENERATED ALWAYS AS (
                                     CASE WHEN GeometryType(geom) IN ('POLYGON','MULTIPOLYGON')
                                          THEN round(ST_Perimeter(ST_Transform(geom, 7791))::numeric, 2) END) STORED,
              version              integer NOT NULL DEFAULT 1,
              created_by           uuid REFERENCES users(id),
              updated_by           uuid REFERENCES users(id),
              created_at           timestamptz NOT NULL DEFAULT now(),
              updated_at           timestamptz NOT NULL DEFAULT now(),
              deleted_at           timestamptz,
              CONSTRAINT ck_assets_valid_dates CHECK (valid_to IS NULL OR valid_to >= valid_from),
              CONSTRAINT ck_assets_geom_not_empty CHECK (NOT ST_IsEmpty(geom))
            );
            CREATE UNIQUE INDEX uq_assets_tenant_census_code
              ON assets (tenant_id, census_code) WHERE deleted_at IS NULL AND census_code IS NOT NULL;
            CREATE INDEX ix_assets_tenant_area   ON assets (tenant_id, area_id)        WHERE deleted_at IS NULL;
            CREATE INDEX ix_assets_tenant_type   ON assets (tenant_id, object_type_id) WHERE deleted_at IS NULL;
            CREATE INDEX ix_assets_tenant_status ON assets (tenant_id, status)         WHERE deleted_at IS NULL;
            CREATE INDEX ix_assets_valid_range   ON assets (tenant_id, valid_from, valid_to);
            CREATE INDEX gist_assets_geom        ON assets USING gist (geom);
            CREATE INDEX gin_assets_attributes   ON assets USING gin (attributes jsonb_path_ops);

            CREATE TABLE asset_versions (
              id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id     uuid NOT NULL REFERENCES organizations(id),
              asset_id      uuid NOT NULL REFERENCES assets(id) ON DELETE CASCADE,
              version       integer NOT NULL,
              snapshot      jsonb NOT NULL,
              diff          jsonb,
              geom          geometry(Geometry,4326),
              changed_by    uuid REFERENCES users(id),
              changed_at    timestamptz NOT NULL DEFAULT now(),
              change_source text NOT NULL DEFAULT 'web'
                            CHECK (change_source IN ('web','pwa','sync','import','api','system')),
              CONSTRAINT uq_asset_versions_asset_version UNIQUE (asset_id, version)
            );
            CREATE INDEX ix_asset_versions_tenant_asset ON asset_versions (tenant_id, asset_id, changed_at DESC);
            CREATE INDEX gist_asset_versions_geom ON asset_versions USING gist (geom);

            CREATE TABLE asset_tags (
              id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id     uuid NOT NULL REFERENCES organizations(id),
              asset_id      uuid REFERENCES assets(id),
              tag_type      text NOT NULL CHECK (tag_type IN ('nfc','qr','barcode','rfid','arbotag')),
              uid           text NOT NULL,
              payload       jsonb NOT NULL DEFAULT '{}'::jsonb,
              status        text NOT NULL DEFAULT 'active'
                            CHECK (status IN ('active','unassigned','replaced','lost','damaged','retired')),
              attached_by   uuid REFERENCES users(id),
              attached_at   timestamptz,
              detached_at   timestamptz,
              detach_reason text,
              created_at    timestamptz NOT NULL DEFAULT now(),
              updated_at    timestamptz NOT NULL DEFAULT now()
            );
            CREATE UNIQUE INDEX uq_asset_tags_uid_active
              ON asset_tags (tenant_id, tag_type, uid) WHERE status IN ('active','unassigned');
            CREATE INDEX ix_asset_tags_tenant_asset ON asset_tags (tenant_id, asset_id);
            CREATE INDEX ix_asset_tags_lookup ON asset_tags (tag_type, uid);

            CREATE TABLE photos (
              id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id         uuid NOT NULL REFERENCES organizations(id),
              asset_id          uuid REFERENCES assets(id),
              subject_type      text,
              subject_id        uuid,
              category          text NOT NULL DEFAULT 'census' CHECK (category IN
                                ('census','reference','before','during','after','organ','defect','issue','signature','other')),
              s3_key            text NOT NULL,
              thumb_s3_key      text,
              original_filename text,
              mime_type         text NOT NULL DEFAULT 'image/jpeg',
              size_bytes        bigint,
              hash_sha256       char(64),
              taken_at          timestamptz,
              exif              jsonb NOT NULL DEFAULT '{}'::jsonb,
              geom              geometry(Point,4326),
              taken_by          uuid REFERENCES users(id),
              created_at        timestamptz NOT NULL DEFAULT now(),
              updated_at        timestamptz NOT NULL DEFAULT now(),
              deleted_at        timestamptz,
              CONSTRAINT ck_photos_subject CHECK (
                (subject_type IS NULL AND subject_id IS NULL) OR (subject_type IS NOT NULL AND subject_id IS NOT NULL))
            );
            CREATE INDEX ix_photos_tenant_asset ON photos (tenant_id, asset_id) WHERE deleted_at IS NULL;
            CREATE INDEX ix_photos_subject ON photos (subject_type, subject_id) WHERE deleted_at IS NULL;
            CREATE INDEX gist_photos_geom ON photos USING gist (geom);

            CREATE TABLE documents (
              id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id    uuid NOT NULL REFERENCES organizations(id),
              asset_id     uuid REFERENCES assets(id),
              subject_type text,
              subject_id   uuid,
              doc_type     text NOT NULL DEFAULT 'other' CHECK (doc_type IN
                           ('report','certificate','datasheet','photo_album','contract','invoice','map','vta_report','other')),
              title        text NOT NULL,
              s3_key       text NOT NULL,
              mime_type    text NOT NULL,
              size_bytes   bigint,
              hash_sha256  char(64),
              acl          jsonb NOT NULL DEFAULT '{}'::jsonb,
              uploaded_by  uuid REFERENCES users(id),
              created_at   timestamptz NOT NULL DEFAULT now(),
              updated_at   timestamptz NOT NULL DEFAULT now(),
              deleted_at   timestamptz,
              CONSTRAINT ck_documents_subject CHECK (
                (subject_type IS NULL AND subject_id IS NULL) OR (subject_type IS NOT NULL AND subject_id IS NOT NULL))
            );
            CREATE INDEX ix_documents_tenant_asset ON documents (tenant_id, asset_id) WHERE deleted_at IS NULL;
            CREATE INDEX ix_documents_subject ON documents (subject_type, subject_id) WHERE deleted_at IS NULL;

            -- Coerenza tra geometria dell'asset e geometria ammessa dal tipo di catalogo
            CREATE OR REPLACE FUNCTION fn_trg_assets_geom_type() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              allowed char(1);
              gtype   text;
            BEGIN
              SELECT allowed_geometry INTO allowed
                FROM catalog_object_types WHERE id = NEW.object_type_id;
              gtype := GeometryType(NEW.geom);
              IF allowed = 'P' AND gtype NOT IN ('POINT','MULTIPOINT') THEN
                RAISE EXCEPTION 'Geometria % non ammessa: il tipo oggetto richiede un punto (P)', gtype;
              ELSIF allowed = 'L' AND gtype NOT IN ('LINESTRING','MULTILINESTRING') THEN
                RAISE EXCEPTION 'Geometria % non ammessa: il tipo oggetto richiede una linea (L)', gtype;
              ELSIF allowed = 'S' AND gtype NOT IN ('POLYGON','MULTIPOLYGON') THEN
                RAISE EXCEPTION 'Geometria % non ammessa: il tipo oggetto richiede una superficie (S)', gtype;
              END IF;
              RETURN NEW;
            END $$;

            CREATE TRIGGER trg_assets_geom_type
              BEFORE INSERT OR UPDATE OF geom, object_type_id ON assets
              FOR EACH ROW EXECUTE FUNCTION fn_trg_assets_geom_type();

            -- Versioning: incrementa version e fotografa lo stato precedente
            CREATE OR REPLACE FUNCTION fn_trg_assets_version_bump() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW IS DISTINCT FROM OLD THEN
                NEW.version := OLD.version + 1;
                NEW.updated_at := now();
              END IF;
              RETURN NEW;
            END $$;

            CREATE TRIGGER trg_assets_version_bump
              BEFORE UPDATE ON assets
              FOR EACH ROW EXECUTE FUNCTION fn_trg_assets_version_bump();

            CREATE OR REPLACE FUNCTION fn_trg_assets_version_snapshot() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW IS DISTINCT FROM OLD THEN
                INSERT INTO asset_versions (tenant_id, asset_id, version, snapshot, geom, changed_by, change_source)
                VALUES (
                  OLD.tenant_id,
                  OLD.id,
                  OLD.version,
                  to_jsonb(OLD) - 'geom',
                  OLD.geom,
                  NEW.updated_by,
                  'web'
                );
              END IF;
              RETURN NEW;
            END $$;

            CREATE TRIGGER trg_assets_version_snapshot
              AFTER UPDATE ON assets
              FOR EACH ROW EXECUTE FUNCTION fn_trg_assets_version_snapshot();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS documents;
            DROP TABLE IF EXISTS photos;
            DROP TABLE IF EXISTS asset_tags;
            DROP TABLE IF EXISTS asset_versions;
            DROP TABLE IF EXISTS assets;
            DROP FUNCTION IF EXISTS fn_trg_assets_geom_type();
            DROP FUNCTION IF EXISTS fn_trg_assets_version_bump();
            DROP FUNCTION IF EXISTS fn_trg_assets_version_snapshot();
        SQL);
    }
};
