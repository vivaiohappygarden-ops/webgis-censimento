<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ricerca senza accenti: "citta" deve trovare "Città".
 *
 * L'estensione unaccent la può creare solo un superutente: in produzione lo fa
 * deploy/update.sh prima delle migrazioni, in sviluppo e nei test di solito
 * l'utente del database può da sé. Qui si TENTA il CREATE EXTENSION e, se non
 * ci sono i permessi, si scrive un avviso e si prosegue: senza estensione la
 * ricerca continua a distinguere gli accenti, come prima, e non si rompe nulla.
 *
 * Quando l'estensione c'è, si crea la funzione senza_accenti(): unaccent() da
 * sola non è IMMUTABLE (dipende dal dizionario cercato in search_path) e
 * PostgreSQL non la accetta in un indice. Con il dizionario scritto per esteso
 * il risultato non cambia mai: questa versione "congelata" si può indicizzare,
 * ed è quella che RicercaTestuale usa nelle query. Gli indici a trigrammi
 * esistenti sono sulle colonne nude e con senza_accenti(colonna) non servono
 * più alla ricerca: se ne creano di nuovi sulla stessa identica espressione
 * usata nelle query. I vecchi restano, per i database dove l'estensione manca
 * e il confronto torna sulle colonne nude.
 */
return new class extends Migration
{
    /**
     * Fuori transazione: se il CREATE EXTENSION fallisse per permessi dentro
     * una transazione, PostgreSQL la considererebbe abortita e anche il
     * try/catch non salverebbe i comandi successivi.
     */
    public $withinTransaction = false;

    /**
     * Le stesse tabelle e colonne degli indici a trigrammi della ricerca
     * (migrazione 2026_08_23_150000_add_search_indexes).
     *
     * @var array<string, list<string>>
     */
    private const CAMPI = [
        'assets' => ['census_code', 'notes'],
        'trees' => ['genus', 'species', 'common_name'],
        'clients' => ['name'],
        'areas' => ['name'],
        'localities' => ['name'],
        'sites' => ['name'],
        'work_orders' => ['code', 'title'],
        'catalog_object_types' => ['name'],
    ];

    public function up(): void
    {
        try {
            // Se l'estensione esiste già (creata dal deploy o da un
            // superutente) IF NOT EXISTS non fa nulla e non chiede permessi
            DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        } catch (\Throwable $e) {
            Log::warning(
                'Estensione unaccent non creata (permessi mancanti?): la ricerca continua '
                .'a distinguere gli accenti. Un superutente può crearla con '
                .'"CREATE EXTENSION unaccent" sul database e rieseguire questa migrazione '
                .'(php artisan migrate:refresh --path=... oppure rollback e migrate).',
                ['errore' => $e->getMessage()],
            );
        }

        if (! DB::table('pg_extension')->where('extname', 'unaccent')->exists()) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.senza_accenti(testo text)
            RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
            AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, testo) $$
        SQL);

        foreach (self::CAMPI as $tabella => $colonne) {
            foreach ($colonne as $colonna) {
                DB::statement(sprintf(
                    'CREATE INDEX IF NOT EXISTS ix_trgm_sa_%1$s_%2$s ON %1$s USING gin (public.senza_accenti(%2$s) gin_trgm_ops)',
                    $tabella, $colonna,
                ));
            }
        }
    }

    public function down(): void
    {
        foreach (self::CAMPI as $tabella => $colonne) {
            foreach ($colonne as $colonna) {
                DB::statement("DROP INDEX IF EXISTS ix_trgm_sa_{$tabella}_{$colonna}");
            }
        }

        DB::statement('DROP FUNCTION IF EXISTS public.senza_accenti(text)');
        // L'estensione non si toglie: l'ha creata un superutente e potrebbe
        // servire ad altro
    }
};
