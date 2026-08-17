<?php

namespace App\Support;

/**
 * Stati di un elemento censito, con le etichette italiane usate ovunque
 * (elenco, esportazioni, stampe): un solo posto da aggiornare.
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

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? (string) $status;
    }
}
