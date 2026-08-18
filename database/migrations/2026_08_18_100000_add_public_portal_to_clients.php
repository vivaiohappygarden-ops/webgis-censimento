<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fondamenta del portale pubblico per committente.
 *
 * - public_slug: prima etichetta del sottodominio (mentana.<dominio>). È
 *   unico su TUTTO l'archivio, non per organizzazione: un sottodominio deve
 *   portare a un solo committente anche quando le imprese sono più di una.
 * - public_enabled: il portale si accende committente per committente.
 * - public_profile: stemma, testi, colore, contatti pubblici del Comune.
 * - label_prefix / label_counter: numerazione delle etichette che riparte da
 *   uno per ogni committente (MEN-0001, GUI-0001). Il contatore è un
 *   massimo storico: non torna indietro se un elemento viene eliminato,
 *   perché il cartellino potrebbe essere già stampato.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE clients
              ADD COLUMN public_slug    text,
              ADD COLUMN public_enabled boolean NOT NULL DEFAULT false,
              ADD COLUMN public_profile jsonb   NOT NULL DEFAULT '{}'::jsonb,
              ADD COLUMN label_prefix   text,
              ADD COLUMN label_counter  integer NOT NULL DEFAULT 0;

            ALTER TABLE clients
              ADD CONSTRAINT ck_clients_public_slug
                CHECK (public_slug IS NULL OR public_slug ~ '^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$'),
              ADD CONSTRAINT ck_clients_label_prefix
                CHECK (label_prefix IS NULL OR label_prefix ~ '^[A-Z0-9]{2,6}$'),
              ADD CONSTRAINT ck_clients_label_counter CHECK (label_counter >= 0);

            -- Unicità globale: il sottodominio identifica il committente da solo
            CREATE UNIQUE INDEX uq_clients_public_slug ON clients (public_slug)
              WHERE deleted_at IS NULL AND public_slug IS NOT NULL;

            -- Il prefisso deve bastare a distinguere i codici dentro l'impresa,
            -- che è l'ambito in cui census_code è unico
            CREATE UNIQUE INDEX uq_clients_label_prefix ON clients (tenant_id, label_prefix)
              WHERE deleted_at IS NULL AND label_prefix IS NOT NULL;

            -- Esclusione del singolo elemento dalla vetrina, decisa dal
            -- backoffice: il portale pubblica tutto tranne questi
            ALTER TABLE assets ADD COLUMN public_hidden boolean NOT NULL DEFAULT false;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE assets DROP COLUMN public_hidden;
            DROP INDEX IF EXISTS uq_clients_label_prefix;
            DROP INDEX IF EXISTS uq_clients_public_slug;
            ALTER TABLE clients
              DROP CONSTRAINT IF EXISTS ck_clients_label_counter,
              DROP CONSTRAINT IF EXISTS ck_clients_label_prefix,
              DROP CONSTRAINT IF EXISTS ck_clients_public_slug;
            ALTER TABLE clients
              DROP COLUMN label_counter,
              DROP COLUMN label_prefix,
              DROP COLUMN public_profile,
              DROP COLUMN public_enabled,
              DROP COLUMN public_slug;
        SQL);
    }
};
