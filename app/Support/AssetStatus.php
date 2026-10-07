<?php

namespace App\Support;

/**
 * Stati di un elemento censito, con le etichette italiane usate ovunque
 * (elenco, esportazioni, stampe): un solo posto da aggiornare.
 *
 * L'ARCHIVIO è l'insieme delle schede fuori dal lavoro quotidiano: gli
 * abbattuti/rimossi e i dismessi (doppioni con collegamenti, elementi che
 * non si gestiscono più). "Morto in piedi" e "ceppaia" NON ne fanno parte:
 * sono elementi veri ancora da gestire (un morto va abbattuto, una ceppaia
 * estirpata). La definizione sta qui e basta: ogni filtro la cita, non la
 * riscrive, altrimenti prima o poi un punto del programma se ne dimentica
 * uno dei due stati.
 */
final class AssetStatus
{
    public const LABELS = [
        'active' => 'attivo',
        'dead' => 'morto in piedi',
        'stump' => 'ceppaia',
        'damaged' => 'danneggiato',
        'out_of_service' => 'fuori servizio',
        'removed' => 'abbattuto/rimosso',
        'dismissed' => 'dismesso',
    ];

    /** Gli stati che compongono l'archivio del censimento. */
    public const ARCHIVIO = ['removed', 'dismissed'];

    /**
     * Gli stati che hanno senso solo per la vegetazione (tipo principale 1
     * del catalogo: alberi, arbusti, siepi, prati, aiuole): una panchina o
     * un gioco non muoiono in piedi e non lasciano una ceppaia. Per il resto
     * del patrimonio restano attivo e dismesso (piu' l'abbattuto/rimosso dal
     * suo flusso). Domanda del committente 07/10/2026: "i parchi e le
     * attrezzature hanno gli stessi stati delle alberature?".
     */
    public const SOLO_VEGETAZIONE = ['dead', 'stump'];

    /**
     * Gli stati delle attrezzature (richiesta del committente 07/10/2026,
     * "metti anche danneggiata e fuori servizio per le attrezzature"): una
     * panchina rotta o un gioco chiuso restano in gestione, come un albero
     * morto in piedi, e non sono archivio. Alla vegetazione non si applicano:
     * la salute di un albero sta nella scheda e nelle valutazioni di stabilita'.
     */
    public const SOLO_ATTREZZATURE = ['damaged', 'out_of_service'];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? (string) $status;
    }

    public static function inArchivio(?string $status): bool
    {
        return in_array($status, self::ARCHIVIO, true);
    }

    /** Il codice del catalogo porta il tipo principale in seconda posizione ("P103108": 1 = vegetazione). */
    public static function eVegetazione(?string $codiceTipo): bool
    {
        return substr((string) $codiceTipo, 1, 1) === '1';
    }

    /**
     * Uno stato vale per questo tipo di elemento? Morto in piedi e ceppaia
     * solo per la vegetazione; danneggiato e fuori servizio solo per il resto.
     */
    public static function ammessoPer(?string $status, ?string $codiceTipo): bool
    {
        if (in_array($status, self::SOLO_VEGETAZIONE, true)) {
            return self::eVegetazione($codiceTipo);
        }
        if (in_array($status, self::SOLO_ATTREZZATURE, true)) {
            return ! self::eVegetazione($codiceTipo);
        }

        return true;
    }

    /** Il messaggio del 422 quando uno stato non vale per il tipo: una frase sola, usata ovunque. */
    public static function motivoNonAmmesso(?string $status): string
    {
        $etichetta = '"'.ucfirst(self::label($status)).'"';
        if (in_array($status, self::SOLO_ATTREZZATURE, true)) {
            return $etichetta.' vale solo per arredi, giochi, percorsi e impianti:'
                .' lo stato di salute della vegetazione si registra nella scheda dell\'albero e nelle valutazioni di stabilita\'.';
        }

        return $etichetta.' vale solo per la vegetazione (alberi, arbusti, siepi):'
            .' per arredi, giochi, percorsi e impianti gli stati sono attivo, danneggiato, fuori servizio e dismesso.';
    }

    /**
     * Gli stati con cui una scheda puo' nascere: tutti meno l'archivio
     * (abbattimento e dismissione hanno i loro flussi, dal gestionale).
     *
     * @return list<string>
     */
    public static function allaNascita(): array
    {
        return array_values(array_diff(array_keys(self::LABELS), self::ARCHIVIO));
    }

    /**
     * L'elenco dell'archivio pronto per una IN (...) in SQL grezzo
     * ("'removed','dismissed'"): sono costanti di codice, non input.
     */
    public static function sqlArchivio(): string
    {
        return "'".implode("','", self::ARCHIVIO)."'";
    }
}
