<?php

namespace App\Services\Pdf;

use Illuminate\Http\Request;

/**
 * Stampe componibili: quali sezioni includere in un PDF.
 *
 * Il parametro ?sezioni=a,b elenca le sezioni volute fra quelle ammesse
 * dalla stampa; senza parametro escono tutte (la stampa di sempre). Le voci
 * sconosciute si ignorano; un elenco esplicitamente vuoto stampa la sola
 * parte fissa del documento (testata e dati generali).
 */
class SezioniStampa
{
    /** @param  list<string>  $ammesse */
    public static function da(Request $request, array $ammesse): array
    {
        // Si guarda la PRESENZA del parametro, non il suo valore: il
        // middleware che converte le stringhe vuote in null trasformerebbe
        // "?sezioni=" (nessuna sezione scelta) in "parametro assente"
        // (tutte le sezioni), cioe' nell'esatto contrario
        $query = $request->query();
        if (! array_key_exists('sezioni', $query)) {
            return $ammesse;
        }

        return array_values(array_intersect(
            $ammesse,
            array_filter(array_map('trim', explode(',', (string) ($query['sezioni'] ?? '')))),
        ));
    }
}
