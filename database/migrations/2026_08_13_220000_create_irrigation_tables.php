<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Irrigazione di base: un impianto per area con i suoi settori e il
     * programma stagionale. Le manutenzioni passano dagli ordini di lavoro
     * (origin maintenance_plan).
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE irrigation_systems (
              id               uuid PRIMARY KEY,
              tenant_id        uuid NOT NULL REFERENCES organizations(id),
              area_id          uuid NOT NULL REFERENCES areas(id),
              name             text NOT NULL,
              system_type      text NOT NULL DEFAULT 'aspersione' CHECK (system_type IN
                               ('aspersione','goccia','subirrigazione','idranti','misto')),
              water_source     text CHECK (water_source IS NULL OR water_source IN
                               ('acquedotto','pozzo','cisterna','corso_acqua','altro')),
              controller_model text,
              status           text NOT NULL DEFAULT 'active' CHECK (status IN
                               ('active','winterized','out_of_service')),
              season_opens_on  date,
              season_closes_on date,
              notes            text,
              created_at       timestamptz NOT NULL DEFAULT now(),
              updated_at       timestamptz NOT NULL DEFAULT now(),
              deleted_at       timestamptz,
              CONSTRAINT ck_irrigation_season CHECK (
                season_opens_on IS NULL OR season_closes_on IS NULL
                OR season_closes_on >= season_opens_on)
            );
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX ix_irrigation_tenant_area ON irrigation_systems (tenant_id, area_id)
              WHERE deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE irrigation_sectors (
              id             uuid PRIMARY KEY,
              tenant_id      uuid NOT NULL REFERENCES organizations(id),
              system_id      uuid NOT NULL REFERENCES irrigation_systems(id) ON DELETE CASCADE,
              sort_order     int NOT NULL DEFAULT 1,
              name           text NOT NULL,
              description    text,
              flow_lpm       numeric(8,2) CHECK (flow_lpm IS NULL OR flow_lpm >= 0),
              run_minutes    int CHECK (run_minutes IS NULL OR (run_minutes BETWEEN 1 AND 1440)),
              runs_per_week  int CHECK (runs_per_week IS NULL OR (runs_per_week BETWEEN 1 AND 28)),
              created_at     timestamptz NOT NULL DEFAULT now(),
              updated_at     timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_irrigation_sector_name UNIQUE (system_id, name)
            );
        SQL);
        DB::statement('CREATE INDEX ix_irrigation_sectors_system ON irrigation_sectors (tenant_id, system_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('irrigation_sectors');
        Schema::dropIfExists('irrigation_systems');
    }
};
