<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preventivi: voci dai listini (o libere) intestate a un cliente, con
     * flusso bozza -> inviato -> accettato/rifiutato e trasformazione in
     * ordine di lavoro (origin estimate).
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE estimates (
              id           uuid PRIMARY KEY,
              tenant_id    uuid NOT NULL REFERENCES organizations(id),
              code         text NOT NULL,
              client_id    uuid NOT NULL REFERENCES clients(id),
              area_id      uuid REFERENCES areas(id),
              title        text NOT NULL,
              status       text NOT NULL DEFAULT 'draft' CHECK (status IN
                           ('draft','sent','accepted','rejected')),
              vat_percent  numeric(5,2) NOT NULL DEFAULT 22 CHECK (vat_percent >= 0 AND vat_percent <= 100),
              valid_until  date,
              notes        text,
              version      int NOT NULL DEFAULT 1,
              created_by   uuid REFERENCES users(id),
              updated_by   uuid REFERENCES users(id),
              created_at   timestamptz NOT NULL DEFAULT now(),
              updated_at   timestamptz NOT NULL DEFAULT now(),
              deleted_at   timestamptz
            );
        SQL);
        DB::statement("CREATE UNIQUE INDEX uq_estimates_tenant_code ON estimates (tenant_id, code) WHERE deleted_at IS NULL");
        DB::statement('CREATE INDEX ix_estimates_tenant_client ON estimates (tenant_id, client_id) WHERE deleted_at IS NULL');

        DB::statement(<<<'SQL'
            CREATE TABLE estimate_items (
              id           uuid PRIMARY KEY,
              tenant_id    uuid NOT NULL REFERENCES organizations(id),
              estimate_id  uuid NOT NULL REFERENCES estimates(id) ON DELETE CASCADE,
              sort_order   int NOT NULL DEFAULT 1,
              work_type_id uuid REFERENCES work_types(id),
              description  text NOT NULL,
              unit         text NOT NULL,
              quantity     numeric(12,2) NOT NULL CHECK (quantity > 0),
              unit_price   numeric(12,2) NOT NULL CHECK (unit_price >= 0),
              created_at   timestamptz NOT NULL DEFAULT now(),
              updated_at   timestamptz NOT NULL DEFAULT now()
            );
        SQL);
        DB::statement('CREATE INDEX ix_estimate_items_estimate ON estimate_items (tenant_id, estimate_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_items');
        Schema::dropIfExists('estimates');
    }
};
