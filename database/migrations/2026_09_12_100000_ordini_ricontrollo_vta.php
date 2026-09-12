<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Il ricontrollo VTA diventa un ordine di lavoro vero.
 *
 * Nuova origine 'vta_recheck' per gli ordini che nascono dallo scadenzario e
 * indice unico che ne tiene uno solo per valutazione: rilanciare la
 * generazione non crea doppioni nemmeno con due lanci simultanei (la
 * garanzia sta sul database, non nel codice, come per i piani).
 *
 * Un ordine annullato resta una decisione presa e continua a coprire la sua
 * valutazione (l'indice conta anche gli annullati); uno eliminato libera la
 * valutazione, che torna generabile.
 */
return new class extends Migration
{
    private const ORIGINI = [
        'manual', 'maintenance_plan', 'issue', 'non_conformity',
        'inspection', 'estimate', 'client_request', 'vta_recheck',
    ];

    public function up(): void
    {
        $this->riscriviVincolo(self::ORIGINI);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uq_work_orders_vta_recheck
            ON work_orders (tenant_id, origin_id)
            WHERE origin = 'vta_recheck' AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_work_orders_vta_recheck');

        // Gli ordini gia' nati dallo scadenzario non si buttano: tornano
        // ordini a mano, cosi' il vincolo stretto puo' essere rimesso
        DB::table('work_orders')->where('origin', 'vta_recheck')
            ->update(['origin' => 'manual', 'origin_id' => null]);

        $this->riscriviVincolo(array_values(array_diff(self::ORIGINI, ['vta_recheck'])));
    }

    /** @param  list<string>  $origini */
    private function riscriviVincolo(array $origini): void
    {
        $elenco = implode(',', array_map(fn (string $o) => "'{$o}'", $origini));

        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_origin_check');
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_origin_check CHECK (origin IN ({$elenco}))");
    }
};
