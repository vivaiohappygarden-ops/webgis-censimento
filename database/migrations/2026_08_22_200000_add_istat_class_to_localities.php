<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Classificazione della località secondo le tipologie di verde urbano della
 * rilevazione ISTAT: serve alla scheda e alle consegne agli enti.
 *
 * Non c'è un vincolo sui valori ammessi nel database: la tassonomia è esterna
 * e cambia con le rilevazioni, e un elenco fissato qui obbligherebbe a una
 * migrazione ogni volta. I valori ammessi stanno in config/istat.php e li
 * fa rispettare la validazione.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE localities ADD COLUMN istat_class text');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE localities DROP COLUMN istat_class');
    }
};
