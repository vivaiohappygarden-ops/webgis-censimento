<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro dei trattamenti fitosanitari (quaderno di campagna, DM
     * 22/01/2014): ogni riga documenta data, prodotto, dose e avversità.
     * Il trattamento è sempre su un'area; l'albero singolo è facoltativo
     * (es. endoterapia su un esemplare). Il tempo di rientro conta per il
     * verde pubblico: l'area va interdetta finché non è trascorso.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE phyto_treatments (
              id                  uuid PRIMARY KEY,
              tenant_id           uuid NOT NULL REFERENCES organizations(id),
              area_id             uuid NOT NULL REFERENCES areas(id),
              asset_id            uuid REFERENCES assets(id),
              treated_on          date NOT NULL,
              product_name        text NOT NULL,
              registration_number text,
              active_substance    text,
              adversity           text NOT NULL,
              method              text NOT NULL DEFAULT 'irrorazione' CHECK (method IN
                                  ('irrorazione','endoterapia','iniezione_suolo','esca','altro')),
              quantity            numeric(10,3) NOT NULL CHECK (quantity > 0),
              unit                text NOT NULL CHECK (unit IN ('l','ml','kg','g')),
              water_volume_l      numeric(8,1) CHECK (water_volume_l IS NULL OR water_volume_l >= 0),
              surface_sqm         numeric(12,2) CHECK (surface_sqm IS NULL OR surface_sqm >= 0),
              reentry_hours       int CHECK (reentry_hours IS NULL OR reentry_hours BETWEEN 0 AND 720),
              operator_id         uuid REFERENCES users(id),
              notes               text,
              version             int NOT NULL DEFAULT 1,
              created_by          uuid REFERENCES users(id),
              updated_by          uuid REFERENCES users(id),
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now(),
              deleted_at          timestamptz
            );
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX ix_phyto_tenant_date ON phyto_treatments (tenant_id, treated_on DESC)
              WHERE deleted_at IS NULL
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX ix_phyto_tenant_area ON phyto_treatments (tenant_id, area_id)
              WHERE deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('phyto_treatments');
    }
};
