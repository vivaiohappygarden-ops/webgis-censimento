<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invii al gestionale WordPress (YourGarden in Cloud): ogni riga è
     * una scheda "intervento da fare / da preventivare" spedita per un
     * elemento del censimento. La chiave external_ref garantisce
     * l'idempotenza lato gestionale: ritrasmettere è sempre sicuro.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE gestionale_dispatches (
              id            uuid PRIMARY KEY,
              tenant_id     uuid NOT NULL REFERENCES organizations(id),
              asset_id      uuid NOT NULL REFERENCES assets(id),
              external_ref  text NOT NULL,
              request_type  text NOT NULL DEFAULT 'intervento' CHECK (request_type IN ('intervento','preventivo')),
              title         text NOT NULL,
              description   text,
              priority      text NOT NULL DEFAULT 'media' CHECK (priority IN ('bassa','media','alta','critica')),
              photo_ids     jsonb NOT NULL DEFAULT '[]',
              status        text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','sent','failed')),
              attempts      int NOT NULL DEFAULT 0,
              last_error    text,
              remote_id     text,
              remote_url    text,
              is_duplicate  boolean NOT NULL DEFAULT false,
              created_by    uuid REFERENCES users(id),
              created_at    timestamptz NOT NULL DEFAULT now(),
              updated_at    timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_dispatch_external_ref UNIQUE (tenant_id, external_ref)
            );
        SQL);
        DB::statement('CREATE INDEX ix_dispatches_tenant_asset ON gestionale_dispatches (tenant_id, asset_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('gestionale_dispatches');
    }
};
