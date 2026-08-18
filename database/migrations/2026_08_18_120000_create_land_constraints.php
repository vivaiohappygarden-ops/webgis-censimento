<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vincoli che gravano sul territorio: paesaggistici, archeologici, storici.
 *
 * Sui portali civici di riferimento la scheda dell'albero elenca i vincoli
 * dell'area in cui ricade, ciascuno con il suo documento scaricabile
 * (es. "art. 28 PTPR - Aree urbanizzate"). Da noi finora esistevano solo due
 * caselle di testo libero sulla scheda albero.
 *
 * Il vincolo è un'anagrafica a sé: si scrive una volta e si collega a molti
 * elementi, a mano oppure per intersezione geografica quando si carica il
 * perimetro.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE land_constraints (
              id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id    uuid NOT NULL REFERENCES organizations(id),
              -- Vincolo di un committente; null = valido per tutta l'impresa
              client_id    uuid REFERENCES clients(id),
              code         text NOT NULL,
              name         text,
              description  text,
              authority    text,
              document_id  uuid REFERENCES documents(id),
              -- Perimetro: senza geometria il vincolo si collega solo a mano
              geom         geometry(MultiPolygon,4326),
              is_public    boolean NOT NULL DEFAULT true,
              created_by   uuid REFERENCES users(id),
              created_at   timestamptz NOT NULL DEFAULT now(),
              updated_at   timestamptz NOT NULL DEFAULT now(),
              deleted_at   timestamptz,
              CONSTRAINT ck_land_constraints_geom CHECK (geom IS NULL OR NOT ST_IsEmpty(geom))
            );
            CREATE INDEX ix_land_constraints_tenant ON land_constraints (tenant_id, client_id)
              WHERE deleted_at IS NULL;
            CREATE INDEX gist_land_constraints_geom ON land_constraints USING gist (geom);

            CREATE TABLE asset_constraints (
              id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id     uuid NOT NULL REFERENCES organizations(id),
              asset_id      uuid NOT NULL REFERENCES assets(id) ON DELETE CASCADE,
              constraint_id uuid NOT NULL REFERENCES land_constraints(id) ON DELETE CASCADE,
              -- 'spatial' = ricavato dal perimetro, 'manual' = deciso a mano.
              -- I collegamenti a mano non vengono toccati dal ricalcolo
              source        text NOT NULL DEFAULT 'manual' CHECK (source IN ('manual', 'spatial')),
              created_at    timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_asset_constraints UNIQUE (asset_id, constraint_id)
            );
            CREATE INDEX ix_asset_constraints_constraint ON asset_constraints (constraint_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS asset_constraints;
            DROP TABLE IF EXISTS land_constraints;
        SQL);
    }
};
