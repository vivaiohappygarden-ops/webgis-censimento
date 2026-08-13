<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Letture del contatore idrico per impianto: il consumo si calcola
     * tra letture consecutive, una al massimo per giorno per impianto.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE irrigation_meter_readings (
              id          uuid PRIMARY KEY,
              tenant_id   uuid NOT NULL REFERENCES organizations(id),
              system_id   uuid NOT NULL REFERENCES irrigation_systems(id) ON DELETE CASCADE,
              read_on     date NOT NULL,
              value_m3    numeric(12,2) NOT NULL CHECK (value_m3 >= 0),
              note        text,
              created_by  uuid REFERENCES users(id),
              created_at  timestamptz NOT NULL DEFAULT now(),
              updated_at  timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_irrigation_reading_day UNIQUE (system_id, read_on)
            );
        SQL);
        DB::statement('CREATE INDEX ix_irrigation_readings_system ON irrigation_meter_readings (tenant_id, system_id, read_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('irrigation_meter_readings');
    }
};
