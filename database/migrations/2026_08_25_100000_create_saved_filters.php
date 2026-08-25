<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Viste salvate: i filtri di un elenco memorizzati con un nome.
 *
 * "Tigli da spollonare nelle scuole" si imposta una volta e si richiama con
 * un clic, invece di ricostruire la ricerca da capo ogni mattina. Una vista
 * puo' essere condivisa con i colleghi e una puo' essere la predefinita,
 * applicata da sola all'apertura della pagina.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE saved_filters (
              id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id   uuid NOT NULL REFERENCES organizations(id),
              user_id     uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              pagina      text NOT NULL,
              nome        text NOT NULL,
              filtri      jsonb NOT NULL DEFAULT '{}'::jsonb,
              predefinita boolean NOT NULL DEFAULT false,
              condivisa   boolean NOT NULL DEFAULT false,
              created_at  timestamptz,
              updated_at  timestamptz,
              CONSTRAINT uq_saved_filters_utente_pagina_nome UNIQUE (user_id, pagina, nome)
            );
            CREATE INDEX ix_saved_filters_tenant_pagina ON saved_filters (tenant_id, pagina);
            -- Una sola predefinita per utente e pagina: lo garantisce il
            -- database, non la buona volonta' del programma
            CREATE UNIQUE INDEX uq_saved_filters_predefinita
              ON saved_filters (user_id, pagina) WHERE predefinita;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS saved_filters');
    }
};
