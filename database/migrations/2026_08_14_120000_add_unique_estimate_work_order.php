<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Un preventivo genera UN solo ordine: il controllo applicativo da solo
     * non regge due richieste simultanee, l'indice univoco sì. Vale solo per
     * origin=estimate: le manutenzioni ricorrenti generano più ordini.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX uq_work_orders_estimate_origin
            ON work_orders (tenant_id, origin_id)
            WHERE origin = 'estimate' AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_work_orders_estimate_origin');
    }
};
