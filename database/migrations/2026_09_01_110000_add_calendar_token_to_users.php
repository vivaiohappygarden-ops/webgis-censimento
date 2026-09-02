<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Gettone del calendario da abbonamento: identifica l'utente nel feed
     * iCal senza sessione. Nasce vuoto e si genera alla prima apertura del
     * pannello; rigenerarlo revoca il vecchio indirizzo (l'unico modo di
     * ritirare un link finito in giro). L'indice unico è parziale: i NULL
     * (utenti che non hanno mai aperto il pannello) non devono collidere.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE users ADD COLUMN calendar_token text');
        DB::statement('CREATE UNIQUE INDEX users_calendar_token_unique ON users (calendar_token) WHERE calendar_token IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_calendar_token_unique');
        DB::statement('ALTER TABLE users DROP COLUMN calendar_token');
    }
};
