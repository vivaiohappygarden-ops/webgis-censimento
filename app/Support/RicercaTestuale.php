<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Ricerca a parole, come la scrive chi cerca.
 *
 * Prima quello che si digitava veniva confrontato tutto intero: cercando
 * "rossi mario" non si trovava "Mario Rossi", perché nel testo quelle due
 * parole non compaiono in quell'ordine e in fila. Qui il testo si spezza in
 * parole e si chiede che ci siano TUTTE - ciascuna in uno qualsiasi dei campi
 * cercati, in qualunque ordine, anche in mezzo a una parola più lunga.
 *
 * Restringere a ogni parola in più è voluto: si scrive per arrivare a un
 * risultato solo, non per allargare l'elenco.
 *
 * Nemmeno gli accenti contano: "citta" trova "Città", "perche" trova
 * "perché". Lo fa la funzione senza_accenti() del database (estensione
 * unaccent, creata dal deploy; la migrazione ricerca_senza_accenti la
 * congela in forma indicizzabile). Dove la funzione manca la ricerca torna
 * da sola al confronto di prima, con gli accenti distinti.
 */
class RicercaTestuale
{
    /**
     * Oltre questo numero le parole in più si ignorano: ognuna aggiunge una
     * condizione alla ricerca, e chi ne scrive dodici sta incollando una frase.
     */
    public const MASSIMO_PAROLE = 6;

    /**
     * Se il database sa togliere gli accenti. Si accerta una volta sola per
     * processo: è una proprietà del database, non cambia a metà richiesta.
     * Niente cache condivisa (file o Redis): un altro processo potrebbe
     * parlare con un database diverso, ognuno guarda il suo.
     */
    private static ?bool $senzaAccenti = null;

    /**
     * Regole di validazione per un campo di ricerca.
     *
     * Il tetto di lunghezza tiene a bada le richieste assurde. Il controllo
     * sui caratteri serve a un caso preciso: con byte non validi in UTF-8
     * PostgreSQL rifiuta la query e la pagina rispondeva "errore del
     * programma"; meglio dire che il testo non si può leggere.
     *
     * @return array<int, mixed>
     */
    public static function regole(): array
    {
        return ['string', 'max:200', function (string $campo, mixed $valore, \Closure $errore) {
            if (is_string($valore) && ! mb_check_encoding($valore, 'UTF-8')) {
                $errore('Il testo cercato contiene caratteri che non si possono leggere: riscrivilo.');
            }
        }];
    }

    /**
     * Applica la ricerca a una query.
     *
     * $colonne sono i campi della tabella; $inoltre serve per cercare anche
     * dentro le tabelle collegate (specie dell'albero, nome dell'area...) e
     * riceve la parola già pronta per il confronto.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  list<string>  $colonne
     * @param  null|callable(mixed, string): void  $inoltre
     */
    public static function applica($query, ?string $testo, array $colonne, ?callable $inoltre = null): void
    {
        foreach (self::parole($testo) as $parola) {
            $query->where(function ($gruppo) use ($parola, $colonne, $inoltre) {
                foreach ($colonne as $indice => $colonna) {
                    self::condizione($gruppo, $colonna, $parola, inOr: $indice > 0);
                }

                if ($inoltre !== null) {
                    $inoltre($gruppo, $parola);
                }
            });
        }
    }

    /**
     * Una parola cercata in più campi della stessa tabella.
     *
     * Serve dentro le ricerche sulle tabelle collegate, dove il gruppo di
     * condizioni è già aperto e va riempito in OR.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  list<string>  $colonne
     */
    public static function inColonne($query, string $parola, array $colonne): void
    {
        $query->where(function ($gruppo) use ($parola, $colonne) {
            foreach ($colonne as $indice => $colonna) {
                self::condizione($gruppo, $colonna, $parola, inOr: $indice > 0);
            }
        });
    }

    /**
     * La singola condizione "questa parola in questo campo".
     *
     * Con senza_accenti() nel database il confronto ignora gli accenti da
     * tutte e due le parti; la parola resta un parametro, con i jolly già
     * schermati da parole() - unaccent non tocca "%", "_" né la barra di
     * schermatura, quindi chi cerca "50%" continua a cercare proprio quello.
     * Il nome del campo arriva dal codice, mai da chi scrive, ma lo si
     * racchiude comunque con la grammatica ("assets.notes" diventa
     * "assets"."notes"): così nell'SQL grezzo non entra testo libero.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $gruppo
     */
    private static function condizione($gruppo, string $colonna, string $parola, bool $inOr): void
    {
        if (! self::databaseSenzaAccenti()) {
            $inOr
                ? $gruppo->orWhere($colonna, 'ilike', $parola)
                : $gruppo->where($colonna, 'ilike', $parola);

            return;
        }

        // La stessa espressione degli indici (senza_accenti(colonna)):
        // scritta diversamente, PostgreSQL non li userebbe
        $sql = 'senza_accenti('.$gruppo->getGrammar()->wrap($colonna).') ilike senza_accenti(?)';

        $inOr
            ? $gruppo->orWhereRaw($sql, [$parola])
            : $gruppo->whereRaw($sql, [$parola]);
    }

    /**
     * Vero se il database ha la funzione senza_accenti() (estensione unaccent
     * più la migrazione che la rende indicizzabile). Si controlla la funzione
     * e non pg_extension: è la funzione che le query usano, e un'estensione
     * creata a mano dopo le migrazioni non basterebbe da sola.
     */
    public static function databaseSenzaAccenti(): bool
    {
        return self::$senzaAccenti ??= (bool) DB::scalar(
            "SELECT to_regproc('public.senza_accenti') IS NOT NULL",
        );
    }

    /**
     * Solo per i test: forza l'esito del controllo (false per provare il
     * comportamento di ripiego senza togliere l'estensione dal database),
     * null per tornare a chiederlo al database.
     */
    public static function forzaSenzaAccenti(?bool $valore): void
    {
        self::$senzaAccenti = $valore;
    }

    /**
     * Le parole del testo, già racchiuse fra i jolly del confronto.
     *
     * @return list<string> per esempio ['%mario%', '%rossi%']
     */
    public static function parole(?string $testo): array
    {
        $pulito = trim((string) $testo);
        if ($pulito === '') {
            return [];
        }

        // Se il testo non e' UTF-8 valido preg_split fallisce: in quel caso
        // vale come una parola sola. Restituire un elenco vuoto vorrebbe dire
        // togliere il filtro e mostrare tutto, cioe' il contrario di quello
        // che e' stato chiesto
        $pezzi = preg_split('/\s+/u', $pulito);
        if ($pezzi === false) {
            $pezzi = [$pulito];
        }

        $parole = array_values(array_filter($pezzi, fn (string $parola) => $parola !== ''));

        return array_map(
            fn (string $parola) => '%'.self::schermaJolly($parola).'%',
            array_slice($parole, 0, self::MASSIMO_PAROLE),
        );
    }

    /**
     * In un confronto ILIKE i caratteri "%" e "_" sono jolly: chi cerca "50%"
     * o "P_103" cerca proprio quei caratteri, non "qualsiasi cosa".
     */
    private static function schermaJolly(string $parola): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $parola);
    }
}
