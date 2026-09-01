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
        'removed' => 'abbattuto/rimosso',
        'dismissed' => 'dismesso',
    ];

    /** Gli stati che compongono l'archivio del censimento. */
    public const ARCHIVIO = ['removed', 'dismissed'];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? (string) $status;
    }

    public static function inArchivio(?string $status): bool
    {
        return in_array($status, self::ARCHIVIO, true);
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
