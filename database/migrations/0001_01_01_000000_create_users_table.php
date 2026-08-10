<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE organizations (
              id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              name          text NOT NULL,
              slug          text NOT NULL,
              vat_number    text,
              metric_srid   integer NOT NULL DEFAULT 7791,
              settings      jsonb NOT NULL DEFAULT '{}'::jsonb,
              branding      jsonb NOT NULL DEFAULT '{}'::jsonb,
              is_active     boolean NOT NULL DEFAULT true,
              created_at    timestamptz NOT NULL DEFAULT now(),
              updated_at    timestamptz NOT NULL DEFAULT now(),
              deleted_at    timestamptz,
              CONSTRAINT uq_organizations_slug UNIQUE (slug),
              CONSTRAINT ck_organizations_metric_srid CHECK (metric_srid IN (7791, 7792, 7793, 7794))
            );

            CREATE TABLE users (
              id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id         uuid NOT NULL REFERENCES organizations(id),
              name              text NOT NULL,
              email             citext NOT NULL,
              username          text,
              email_verified_at timestamptz,
              password          text,
              remember_token    varchar(100),
              phone             text,
              user_type         text NOT NULL DEFAULT 'internal'
                                CHECK (user_type IN ('internal','client_portal','public','api')),
              mfa_enabled       boolean NOT NULL DEFAULT false,
              mfa_secret        text,
              locale            text NOT NULL DEFAULT 'it',
              last_login_at     timestamptz,
              is_active         boolean NOT NULL DEFAULT true,
              created_at        timestamptz NOT NULL DEFAULT now(),
              updated_at        timestamptz NOT NULL DEFAULT now(),
              deleted_at        timestamptz
            );
            CREATE UNIQUE INDEX uq_users_tenant_email ON users (tenant_id, email) WHERE deleted_at IS NULL;
            CREATE INDEX ix_users_tenant ON users (tenant_id) WHERE deleted_at IS NULL;
        SQL);

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('organizations');
    }
};
