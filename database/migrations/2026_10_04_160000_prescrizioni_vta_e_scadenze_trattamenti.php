<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dall'elenco del committente del 04/10/2026, punti 7 e 11.
 *
 * - Le prescrizioni di una valutazione VTA portano la data entro cui fare
 *   l'intervento (prescriptions_due_on) e diventano ordini di lavoro con
 *   origine 'vta_prescription': un ordine per valutazione, garantito
 *   dall'indice unico come per i ricontrolli.
 * - I trattamenti hanno un tipo (fitosanitario, diserbo, concimazione,
 *   biostimolante, altro) e la data del prossimo intervento (next_due_on):
 *   le concimazioni e gli altri prodotti si registrano nello stesso posto
 *   ma restano fuori dal registro dei trattamenti fitosanitari.
 */
return new class extends Migration
{
    private const ORIGINI = [
        'manual', 'maintenance_plan', 'issue', 'non_conformity',
        'inspection', 'estimate', 'client_request', 'vta_recheck', 'vta_prescription',
    ];

    private const TIPI_TRATTAMENTO = ['fitosanitario', 'diserbo', 'concimazione', 'biostimolante', 'altro'];

    public function up(): void
    {
        Schema::table('tree_assessments', function (Blueprint $table) {
            $table->date('prescriptions_due_on')->nullable()->after('prescriptions');
        });

        $this->riscriviVincolo(self::ORIGINI);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uq_work_orders_vta_prescription
            ON work_orders (tenant_id, origin_id)
            WHERE origin = 'vta_prescription' AND deleted_at IS NULL
        SQL);

        Schema::table('phyto_treatments', function (Blueprint $table) {
            $table->string('kind', 30)->default('fitosanitario')->after('asset_id');
            $table->date('next_due_on')->nullable()->after('treated_on');
            $table->index(['tenant_id', 'next_due_on'], 'phyto_treatments_prossimo_idx');
        });
        $tipi = implode(',', array_map(fn (string $t) => "'{$t}'", self::TIPI_TRATTAMENTO));
        DB::statement("ALTER TABLE phyto_treatments ADD CONSTRAINT phyto_treatments_kind_check CHECK (kind IN ({$tipi}))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE phyto_treatments DROP CONSTRAINT IF EXISTS phyto_treatments_kind_check');
        Schema::table('phyto_treatments', function (Blueprint $table) {
            $table->dropIndex('phyto_treatments_prossimo_idx');
            $table->dropColumn(['kind', 'next_due_on']);
        });

        DB::statement('DROP INDEX IF EXISTS uq_work_orders_vta_prescription');
        // Gli ordini nati dalle prescrizioni restano come ordini a mano
        DB::table('work_orders')->where('origin', 'vta_prescription')->update(['origin' => 'manual', 'origin_id' => null]);
        $this->riscriviVincolo(array_values(array_diff(self::ORIGINI, ['vta_prescription'])));

        Schema::table('tree_assessments', function (Blueprint $table) {
            $table->dropColumn('prescriptions_due_on');
        });
    }

    /** @param  list<string>  $origini */
    private function riscriviVincolo(array $origini): void
    {
        $elenco = implode(',', array_map(fn (string $o) => "'{$o}'", $origini));
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT IF EXISTS work_orders_origin_check');
        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_origin_check CHECK (origin IN ({$elenco}))");
    }
};
