<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stati di avanzamento lavori (SAL).
 *
 * Un SAL raccoglie i lavori completati di un committente in un periodo e ne
 * fotografa le righe valorizzate dal listino (quantita', prezzo, IVA per
 * riga). Bozza finche' non viene validato a mano: da li' e' immutabile e
 * porta il numero; "fatturato" e' solo un segno, i pagamenti non si
 * gestiscono. Un ordine di lavoro puo' stare in un solo SAL: lo garantisce
 * l'indice unico sul database, non il codice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE sals (
              id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id     uuid NOT NULL REFERENCES organizations(id),
              client_id     uuid NOT NULL REFERENCES clients(id),
              code          text,
              period_from   date NOT NULL,
              period_to     date NOT NULL,
              status        text NOT NULL DEFAULT 'bozza'
                            CHECK (status IN ('bozza', 'validato', 'fatturato')),
              notes         text,
              overhead_pct  numeric(5,2),
              validated_at  timestamptz,
              validated_by  uuid REFERENCES users(id),
              invoiced_at   timestamptz,
              invoiced_by   uuid REFERENCES users(id),
              invoice_ref   text,
              created_by    uuid REFERENCES users(id),
              updated_by    uuid REFERENCES users(id),
              created_at    timestamptz,
              updated_at    timestamptz,
              CHECK (period_from <= period_to)
            )
        SQL);
        DB::statement('CREATE INDEX idx_sals_tenant_status ON sals (tenant_id, status)');
        DB::statement('CREATE UNIQUE INDEX uq_sals_code ON sals (tenant_id, code) WHERE code IS NOT NULL');

        DB::statement(<<<'SQL'
            CREATE TABLE sal_items (
              id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id     uuid NOT NULL REFERENCES organizations(id),
              sal_id        uuid NOT NULL REFERENCES sals(id) ON DELETE CASCADE,
              work_order_id uuid NOT NULL REFERENCES work_orders(id),
              descrizione   text NOT NULL,
              unit          text,
              quantity      numeric(12,2),
              unit_price    numeric(12,2),
              imponibile    numeric(12,2) NOT NULL DEFAULT 0,
              vat_rate      numeric(5,2) NOT NULL DEFAULT 22,
              nota          text,
              sort_order    integer NOT NULL DEFAULT 0,
              created_at    timestamptz,
              updated_at    timestamptz
            )
        SQL);
        // "Un ordine, un solo SAL" lo garantisce l'indice unico: eliminando
        // una bozza (CASCADE) l'ordine torna libero da solo
        DB::statement('CREATE UNIQUE INDEX uq_sal_items_ordine ON sal_items (work_order_id)');
        DB::statement('CREATE INDEX idx_sal_items_sal ON sal_items (sal_id)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS sal_items');
        DB::statement('DROP TABLE IF EXISTS sals');
    }
};
