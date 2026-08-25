<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La fotografia di versione include anche la scheda albero e il posto libero.
 *
 * Prima la fotografia copriva solo la riga della scheda base: cambiando la
 * specie o una misura, lo storico non aveva nulla da mostrare, e sono proprio
 * i campi che si correggono piu' spesso. La specializzazione entra nella
 * fotografia sotto le chiavi "albero" e "posto".
 *
 * Ordine delle scritture: il programma incrementa la versione della scheda
 * PRIMA di salvare la scheda albero, cosi' la fotografia (che scatta
 * sull'aggiornamento di assets) riprende ancora i valori vecchi.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_trg_assets_version_snapshot() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.version IS DISTINCT FROM OLD.version THEN
                INSERT INTO asset_versions (tenant_id, asset_id, version, snapshot, geom, changed_by, change_source)
                VALUES (
                  OLD.tenant_id,
                  OLD.id,
                  OLD.version,
                  to_jsonb(OLD) - 'geom'
                    || COALESCE(
                         (SELECT jsonb_build_object('albero',
                                   to_jsonb(t) - 'asset_id' - 'tenant_id' - 'created_at' - 'updated_at')
                          FROM trees t WHERE t.asset_id = OLD.id),
                         '{}'::jsonb)
                    || COALESCE(
                         (SELECT jsonb_build_object('posto',
                                   to_jsonb(p) - 'asset_id' - 'tenant_id' - 'created_at' - 'updated_at')
                          FROM planting_sites p WHERE p.asset_id = OLD.id),
                         '{}'::jsonb),
                  OLD.geom,
                  NEW.updated_by,
                  'web'
                );
              END IF;
              RETURN NEW;
            END $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_trg_assets_version_snapshot() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.version IS DISTINCT FROM OLD.version THEN
                INSERT INTO asset_versions (tenant_id, asset_id, version, snapshot, geom, changed_by, change_source)
                VALUES (OLD.tenant_id, OLD.id, OLD.version, to_jsonb(OLD) - 'geom', OLD.geom, NEW.updated_by, 'web');
              END IF;
              RETURN NEW;
            END $$;
        SQL);
    }
};
