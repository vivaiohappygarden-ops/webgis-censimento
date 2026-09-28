<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Marche temporali (28/09/2026): ogni riga e' un documento chiuso (perizia
 * validata, verbale, registro) al quale una TSA ha apposto la data certa.
 * Si conservano il PDF esatto che e' stato marcato e il gettone (.tsr):
 * ristampare il documento potrebbe dare byte diversi, e la marca vale solo
 * per quei byte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE marche_temporali (
              id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id       uuid NOT NULL REFERENCES organizations(id),
              tipo            text NOT NULL,
              soggetto_id     uuid,
              parametri       jsonb,
              titolo          text NOT NULL,
              nome_file       text NOT NULL,
              sha256          char(64) NOT NULL,
              dimensione      bigint NOT NULL,
              percorso_pdf    text NOT NULL,
              percorso_marca  text NOT NULL,
              generato_il     timestamptz NOT NULL,
              seriale         text,
              tsa             text,
              policy          text,
              servizio        text NOT NULL,
              account         char(64) NOT NULL,
              richiesta_da    uuid REFERENCES users(id) ON DELETE SET NULL,
              created_at      timestamptz,
              updated_at      timestamptz
            );
            CREATE INDEX ix_marche_temporali_tenant_tipo ON marche_temporali (tenant_id, tipo, soggetto_id);
            CREATE INDEX ix_marche_temporali_tenant_data ON marche_temporali (tenant_id, generato_il DESC);
            -- La quota giornaliera si conta per account del fornitore, su tutte
            -- le organizzazioni che lo condividono
            CREATE INDEX ix_marche_temporali_account_data ON marche_temporali (account, created_at);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS marche_temporali');
    }
};
