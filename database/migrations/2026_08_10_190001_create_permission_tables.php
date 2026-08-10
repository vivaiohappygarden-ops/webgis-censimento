<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Schema compatibile con spatie/laravel-permission (teams = tenant_id, PK uuid).
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE permissions (
              id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              name        text NOT NULL,
              guard_name  text NOT NULL DEFAULT 'web',
              created_at  timestamptz NOT NULL DEFAULT now(),
              updated_at  timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_permissions_name_guard UNIQUE (name, guard_name)
            );

            CREATE TABLE roles (
              id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id   uuid REFERENCES organizations(id),
              name        text NOT NULL,
              guard_name  text NOT NULL DEFAULT 'web',
              is_system   boolean NOT NULL DEFAULT false,
              created_at  timestamptz NOT NULL DEFAULT now(),
              updated_at  timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_roles_tenant_name_guard UNIQUE NULLS NOT DISTINCT (tenant_id, name, guard_name)
            );

            CREATE TABLE role_has_permissions (
              permission_id uuid NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
              role_id       uuid NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
              PRIMARY KEY (permission_id, role_id)
            );

            CREATE TABLE model_has_roles (
              role_id     uuid NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
              model_type  text NOT NULL,
              model_id    uuid NOT NULL,
              tenant_id   uuid NOT NULL REFERENCES organizations(id),
              PRIMARY KEY (tenant_id, role_id, model_id, model_type)
            );
            CREATE INDEX ix_model_has_roles_model ON model_has_roles (model_id, model_type);

            CREATE TABLE model_has_permissions (
              permission_id uuid NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
              model_type    text NOT NULL,
              model_id      uuid NOT NULL,
              tenant_id     uuid NOT NULL REFERENCES organizations(id),
              PRIMARY KEY (tenant_id, permission_id, model_id, model_type)
            );
            CREATE INDEX ix_model_has_permissions_model ON model_has_permissions (model_id, model_type);
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
