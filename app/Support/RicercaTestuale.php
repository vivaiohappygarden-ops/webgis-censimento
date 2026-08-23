<?php

namespace App\Support;

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
 */
class RicercaTestuale
{
    /**
     * Oltre questo numero le parole in più si ignorano: ognuna aggiunge una
     * condizione alla ricerca, e chi ne scrive dodici sta incollando una frase.
     */
    public const MASSIMO_PAROLE = 6;

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
                    $indice === 0
                        ? $gruppo->where($colonna, 'ilike', $parola)
                        : $gruppo->orWhere($colonna, 'ilike', $parola);
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
                $indice === 0
                    ? $gruppo->where($colonna, 'ilike', $parola)
                    : $gruppo->orWhere($colonna, 'ilike', $parola);
            }
        });
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
