<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Atti pubblicabili sul portale del committente.
 *
 * Sui portali civici di riferimento la cronologia di un albero mostra anche
 * gli atti amministrativi collegati: l'ordine di servizio con cui l'ente ha
 * disposto l'intervento e la relazione tecnica del professionista. Da noi
 * quei due documenti esistono già (ordine di lavoro con il suo codice,
 * perizia con il suo protocollo): manca solo il consenso a pubblicarli.
 *
 * La pubblicazione è sempre una scelta esplicita, mai automatica.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE work_orders ADD COLUMN is_public boolean NOT NULL DEFAULT false;
            ALTER TABLE tree_assessments ADD COLUMN is_public boolean NOT NULL DEFAULT false;

            -- La cronologia pubblica di un elemento pesca fra i lavori
            -- pubblicabili: l'indice evita la scansione di tutti gli ordini
            CREATE INDEX ix_work_orders_public ON work_orders (tenant_id, completed_at DESC)
              WHERE deleted_at IS NULL AND is_public;
            CREATE INDEX ix_tree_assessments_public ON tree_assessments (tenant_id, tree_id, assessed_on DESC)
              WHERE deleted_at IS NULL AND is_public;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS ix_tree_assessments_public;
            DROP INDEX IF EXISTS ix_work_orders_public;
            ALTER TABLE tree_assessments DROP COLUMN is_public;
            ALTER TABLE work_orders DROP COLUMN is_public;
        SQL);
    }
};
