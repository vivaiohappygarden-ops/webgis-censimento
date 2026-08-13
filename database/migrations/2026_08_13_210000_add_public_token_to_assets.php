<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Pagina pubblica dell'elemento (raggiunta dal QR sul cartellino):
     * esiste solo se il gettone è valorizzato — attivazione per elemento,
     * mai automatica.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE assets ADD COLUMN IF NOT EXISTS public_token text');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_assets_public_token ON assets (public_token) WHERE public_token IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_assets_public_token');
        DB::statement('ALTER TABLE assets DROP COLUMN IF EXISTS public_token');
    }
};
