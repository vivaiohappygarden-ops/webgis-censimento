<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS postgis;
            CREATE EXTENSION IF NOT EXISTS pg_trgm;
            CREATE EXTENSION IF NOT EXISTS citext;
            CREATE EXTENSION IF NOT EXISTS btree_gist;

            CREATE OR REPLACE FUNCTION fn_trg_set_updated_at() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              NEW.updated_at := now();
              RETURN NEW;
            END $$;

            CREATE OR REPLACE FUNCTION fn_trg_forbid_change() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'La tabella % e'' append-only: % non consentito', TG_TABLE_NAME, TG_OP;
            END $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS fn_trg_set_updated_at();
            DROP FUNCTION IF EXISTS fn_trg_forbid_change();
        SQL);
    }
};
