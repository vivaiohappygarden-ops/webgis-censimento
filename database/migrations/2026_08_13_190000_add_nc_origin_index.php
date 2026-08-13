<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Le non conformità vengono cercate per origine (es. le NC aperte da
     * una singola ispezione, nel verbale): senza indice ogni stampa
     * scorrerebbe tutte le NC del tenant.
     */
    public function up(): void
    {
        DB::statement('
            CREATE INDEX IF NOT EXISTS ix_nc_tenant_origin
            ON non_conformities (tenant_id, origin, origin_id)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ix_nc_tenant_origin');
    }
};
