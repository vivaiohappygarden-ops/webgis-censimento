<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * La segnalazione dal portale ricorda PER QUALE cliente fu inviata:
     * la visibilità non può dipendere dal collegamento attuale dell'utente
     * (un utente ricollegato a un altro cliente travaserebbe lo storico).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE issues ADD COLUMN client_id uuid REFERENCES clients(id)');
        DB::statement('CREATE INDEX ix_issues_tenant_client ON issues (tenant_id, client_id) WHERE client_id IS NOT NULL');

        // Riporto per le richieste già inviate: il miglior dato disponibile
        // è il cliente attuale del segnalante
        DB::statement(<<<'SQL'
            UPDATE issues SET client_id = users.client_id
            FROM users
            WHERE issues.reporter_user_id = users.id
              AND issues.channel = 'client_portal'
              AND issues.client_id IS NULL
              AND users.client_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE issues DROP COLUMN IF EXISTS client_id');
    }
};
