<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Il registro dei trattamenti richiede anche la coltura/vegetazione
     * trattata (DPR 290/2001 art. 42, richiamato dal PAN): senza questo
     * campo il registro esibito a un controllo sarebbe incompleto.
     * Nullable per le righe esistenti; l'obbligo sta nella validazione.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE phyto_treatments ADD COLUMN vegetation text');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE phyto_treatments DROP COLUMN vegetation');
    }
};
