<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE audit_logs (
              id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id    uuid REFERENCES organizations(id),
              user_id      uuid REFERENCES users(id),
              action       text NOT NULL,             -- es. 'login', 'asset.created', 'export.cam'
              subject_type text,
              subject_id   uuid,
              payload      jsonb NOT NULL DEFAULT '{}'::jsonb,
              ip_address   inet,
              user_agent   text,
              created_at   timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX ix_audit_logs_tenant_created ON audit_logs (tenant_id, created_at DESC);
            CREATE INDEX ix_audit_logs_subject ON audit_logs (subject_type, subject_id);
            CREATE INDEX ix_audit_logs_user ON audit_logs (user_id, created_at DESC);

            -- Append-only: nessun UPDATE/DELETE applicativo
            CREATE TRIGGER trg_audit_logs_append_only
              BEFORE UPDATE OR DELETE ON audit_logs
              FOR EACH ROW EXECUTE FUNCTION fn_trg_forbid_change();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS audit_logs;');
    }
};
