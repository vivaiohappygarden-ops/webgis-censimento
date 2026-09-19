<?php

namespace App\Support;

/**
 * I dati del sito aziendale, letti da config/sito.php e puliti una volta sola.
 *
 * Le pagine non leggono mai config('sito.…') direttamente: passano da qui,
 * cosi' la regola "quello che e' vuoto non si stampa" sta in un posto solo.
 * Vuoto vuol dire: stringa vuota, null, soli spazi, elenco senza voci.
 */
final class SitoDati
{
    /** Un valore di testo pulito, o null se non c'e' niente da stampare. */
    public static function testo(string $chiave): ?string
    {
        return self::pulisci(config('sito.'.$chiave));
    }

    /**
     * Un gruppo di valori (azienda, contatti, perizie, portale_esempio): solo le
     * voci compilate, nell'ordine in cui sono dichiarate nel file.
     *
     * @return array<string, string>
     */
    public static function gruppo(string $chiave): array
    {
        $voci = [];
        foreach ((array) config('sito.'.$chiave, []) as $nome => $valore) {
            if (($pulito = self::pulisci($valore)) !== null) {
                $voci[$nome] = $pulito;
            }
        }

        return $voci;
    }

    /**
     * Un elenco (professionisti, referenze, lavori): le voci vuote spariscono,
     * dentro ogni voce spariscono i campi vuoti.
     *
     * @return list<string|array<string, string>>
     */
    public static function elenco(string $chiave): array
    {
        $voci = [];
        foreach ((array) config('sito.'.$chiave, []) as $voce) {
            if (is_array($voce)) {
                $campi = [];
                foreach ($voce as $nome => $valore) {
                    if (($pulito = self::pulisci($valore)) !== null) {
                        $campi[$nome] = $pulito;
                    }
                }
                if ($campi !== []) {
                    $voci[] = $campi;
                }
            } elseif (($pulito = self::pulisci($voce)) !== null) {
                $voci[] = $pulito;
            }
        }

        return $voci;
    }

    /** Il collegamento tel: di un numero scritto come lo si legge ("06 12 34 56 78"). */
    public static function telefonoHref(string $telefono): string
    {
        return 'tel:'.preg_replace('/[^+0-9]/', '', $telefono);
    }

    /**
     * Come si presenta chi firma la documentazione tecnica: nome e titolo dalla
     * configurazione, oppure null. Il titolo da solo non basta a scrivere una
     * frase vera, quindi senza firmatario non si stampa niente.
     */
    public static function firmatario(): ?string
    {
        $perizie = self::gruppo('perizie');
        if (! isset($perizie['firmatario'])) {
            return null;
        }

        return isset($perizie['titolo']) ? $perizie['firmatario'].', '.$perizie['titolo'] : $perizie['firmatario'];
    }

    /**
     * I problemi del file di configurazione, spiegati in italiano. La prova
     * SitoAziendaleTest li vuole vuoti: e' la validazione del file.
     *
     * @return list<string>
     */
    public static function problemi(): array
    {
        $problemi = [];

        $dominio = self::testo('base_host');
        if ($dominio !== null && ! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $dominio)) {
            $problemi[] = "Il dominio di produzione va scritto nudo, minuscolo e senza schema (es. esempio.it): trovato \"{$dominio}\".";
        }
        if ($dominio !== null && str_starts_with($dominio, 'www.')) {
            $problemi[] = 'Il dominio di produzione va scritto senza www: il www ci arriva da solo.';
        }

        foreach (['contatti.email' => 'email', 'contatti.pec' => 'PEC'] as $chiave => $nome) {
            $valore = self::testo($chiave);
            if ($valore !== null && filter_var($valore, FILTER_VALIDATE_EMAIL) === false) {
                $problemi[] = "L'indirizzo {$nome} non e' un indirizzo di posta valido: \"{$valore}\".";
            }
        }

        $portale = self::gruppo('portale_esempio');
        if (isset($portale['url']) && (filter_var($portale['url'], FILTER_VALIDATE_URL) === false || ! preg_match('#^https?://#', $portale['url']))) {
            $problemi[] = "L'indirizzo del portale di esempio non e' un indirizzo completo (https://…): \"{$portale['url']}\".";
        }
        if (isset($portale['nome']) && ! isset($portale['url'])) {
            $problemi[] = 'Il portale di esempio ha un nome ma non un indirizzo: senza indirizzo non compare.';
        }

        if (self::testo('perizie.titolo') !== null && self::testo('perizie.firmatario') === null) {
            $problemi[] = 'Le perizie hanno un titolo professionale ma nessun firmatario: il titolo da solo non si stampa.';
        }

        foreach (['nome', 'sottotitolo'] as $chiave) {
            if (self::testo($chiave) === null) {
                $problemi[] = "La voce \"{$chiave}\" non puo' restare vuota: e' il marchio in testata.";
            }
        }

        foreach (['azienda', 'contatti', 'perizie', 'portale_esempio'] as $gruppo) {
            foreach ((array) config('sito.'.$gruppo, []) as $nome => $valore) {
                if ($valore !== null && ! is_string($valore) && ! is_numeric($valore)) {
                    $problemi[] = "La voce {$gruppo}.{$nome} deve essere un testo.";
                }
            }
        }
        foreach (['professionisti', 'referenze', 'lavori'] as $chiave) {
            if (! is_array(config('sito.'.$chiave, []))) {
                $problemi[] = "La voce \"{$chiave}\" deve essere un elenco.";
            }
        }
        foreach (self::elenco('referenze') as $voce) {
            if (! is_array($voce) || ! isset($voce['ente'])) {
                $problemi[] = "Ogni referenza deve avere almeno l'ente.";
                break;
            }
        }
        foreach (self::elenco('lavori') as $voce) {
            if (! is_array($voce) || ! isset($voce['titolo'])) {
                $problemi[] = 'Ogni lavoro eseguito deve avere almeno il titolo.';
                break;
            }
        }

        return $problemi;
    }

    /**
     * I dati societari con la loro etichetta, nell'ordine di lettura: solo
     * quelli compilati. Li usano "Chi siamo" e l'informativa.
     *
     * @return array<string, string>
     */
    public static function datiSocietari(): array
    {
        $a = self::gruppo('azienda');
        $c = self::gruppo('contatti');

        return array_filter([
            'Ragione sociale' => $a['ragione_sociale'] ?? null,
            'Sede legale' => $a['sede'] ?? null,
            'Codice fiscale' => $a['codice_fiscale'] ?? null,
            'Partita IVA' => $a['piva'] ?? null,
            'Registro delle Imprese' => $a['registro_imprese'] ?? null,
            'REA' => $a['rea'] ?? null,
            'Capitale sociale' => $a['capitale_sociale'] ?? null,
            'PEC' => $c['pec'] ?? null,
        ]);
    }

    /**
     * Le stesse informazioni in righe brevi per il pie' di pagina: codice
     * fiscale e partita IVA in una riga sola quando coincidono.
     *
     * @return list<string>
     */
    public static function righeSocietarie(): array
    {
        $a = self::gruppo('azienda');
        $righe = [];
        if (isset($a['sede'])) {
            $righe[] = 'Sede legale: '.$a['sede'];
        }
        $cf = $a['codice_fiscale'] ?? null;
        $piva = $a['piva'] ?? null;
        if ($cf !== null && $cf === $piva) {
            $righe[] = 'Codice fiscale e partita IVA '.$cf;
        } else {
            if ($cf !== null) {
                $righe[] = 'Codice fiscale '.$cf;
            }
            if ($piva !== null) {
                $righe[] = 'Partita IVA '.$piva;
            }
        }
        if (isset($a['registro_imprese'])) {
            $righe[] = $a['registro_imprese'];
        }
        if (isset($a['rea'])) {
            $righe[] = 'REA '.$a['rea'];
        }
        if (isset($a['capitale_sociale'])) {
            $righe[] = 'Capitale sociale '.$a['capitale_sociale'];
        }

        return $righe;
    }

    private static function pulisci(mixed $valore): ?string
    {
        if (! is_string($valore) && ! is_numeric($valore)) {
            return null;
        }
        $valore = trim((string) $valore);

        return $valore === '' ? null : $valore;
    }
}
