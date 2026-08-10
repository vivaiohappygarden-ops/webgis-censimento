<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE catalog_main_types (
              id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id   uuid NOT NULL REFERENCES organizations(id),
              code        varchar(4) NOT NULL,
              name        text NOT NULL,
              is_cam      boolean NOT NULL DEFAULT true,
              sort_order  integer NOT NULL DEFAULT 0,
              created_at  timestamptz NOT NULL DEFAULT now(),
              updated_at  timestamptz NOT NULL DEFAULT now(),
              deleted_at  timestamptz
            );
            CREATE UNIQUE INDEX uq_cat_main_tenant_code ON catalog_main_types (tenant_id, code) WHERE deleted_at IS NULL;

            CREATE TABLE catalog_sub_types (
              id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id     uuid NOT NULL REFERENCES organizations(id),
              main_type_id  uuid NOT NULL REFERENCES catalog_main_types(id),
              code          varchar(4) NOT NULL,
              name          text NOT NULL,
              is_cam        boolean NOT NULL DEFAULT true,
              sort_order    integer NOT NULL DEFAULT 0,
              created_at    timestamptz NOT NULL DEFAULT now(),
              updated_at    timestamptz NOT NULL DEFAULT now(),
              deleted_at    timestamptz
            );
            CREATE UNIQUE INDEX uq_cat_sub_tenant_main_code
              ON catalog_sub_types (tenant_id, main_type_id, code) WHERE deleted_at IS NULL;

            CREATE TABLE catalog_object_types (
              id                   uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id            uuid NOT NULL REFERENCES organizations(id),
              sub_type_id          uuid NOT NULL REFERENCES catalog_sub_types(id),
              code                 varchar(10) NOT NULL,
              name                 text NOT NULL,
              allowed_geometry     char(1) NOT NULL CHECK (allowed_geometry IN ('P','L','S')),
              cam_layer            varchar(3),
              is_cam               boolean NOT NULL DEFAULT false,
              survey_mode          text,
              reference_photo_key  text,
              requires_tree_record boolean NOT NULL DEFAULT false,
              is_planting_site     boolean NOT NULL DEFAULT false,
              icon                 text,
              style                jsonb NOT NULL DEFAULT '{}'::jsonb,
              sort_order           integer NOT NULL DEFAULT 0,
              created_at           timestamptz NOT NULL DEFAULT now(),
              updated_at           timestamptz NOT NULL DEFAULT now(),
              deleted_at           timestamptz,
              CONSTRAINT ck_cat_obj_cam_layer CHECK (cam_layer IS NULL OR cam_layer IN
                ('P1','L1','S1','P2','L2','S2','P3','L3','S3','P4','L4','S4'))
            );
            CREATE UNIQUE INDEX uq_cat_obj_tenant_code ON catalog_object_types (tenant_id, code) WHERE deleted_at IS NULL;
            CREATE INDEX ix_cat_obj_tenant_sub ON catalog_object_types (tenant_id, sub_type_id) WHERE deleted_at IS NULL;

            CREATE TABLE custom_fields (
              id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id      uuid NOT NULL REFERENCES organizations(id),
              object_type_id uuid NOT NULL REFERENCES catalog_object_types(id),
              key            text NOT NULL,
              label          text NOT NULL,
              field_type     text NOT NULL CHECK (field_type IN
                             ('text','textarea','integer','number','boolean','date','select','multiselect','photo','url')),
              required       boolean NOT NULL DEFAULT false,
              options        jsonb NOT NULL DEFAULT '[]'::jsonb,
              validation     jsonb NOT NULL DEFAULT '{}'::jsonb,
              unit           text,
              show_in_pwa    boolean NOT NULL DEFAULT true,
              cam_field      text,
              sort_order     integer NOT NULL DEFAULT 0,
              created_at     timestamptz NOT NULL DEFAULT now(),
              updated_at     timestamptz NOT NULL DEFAULT now(),
              deleted_at     timestamptz
            );
            CREATE UNIQUE INDEX uq_custom_fields_type_key
              ON custom_fields (tenant_id, object_type_id, key) WHERE deleted_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_fields');
        Schema::dropIfExists('catalog_object_types');
        Schema::dropIfExists('catalog_sub_types');
        Schema::dropIfExists('catalog_main_types');
    }
};
