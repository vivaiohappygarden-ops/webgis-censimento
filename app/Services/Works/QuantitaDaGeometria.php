<?php

namespace App\Services\Works;

use App\Models\Asset;

/**
 * La regola che collega l'unità di misura di una lavorazione alla misura
 * calcolata dalla geometria dell'elemento: metri quadrati dalla superficie
 * del poligono, metri dalla lunghezza della linea, "cadauno" vale uno per
 * elemento. Sta scritta una volta sola: la usano l'aggancio degli elementi
 * agli ordini di lavoro, il ricalcolo dal dettaglio dell'ordine e le voci
 * di preventivo collegate a un elemento censito.
 */
class QuantitaDaGeometria
{
    private const SUPERFICIE = ['mq', 'm2', 'm²', 'metri quadrati', 'metri quadri', 'metro quadrato', 'metro quadro'];

    private const LUNGHEZZA = ['m', 'ml', 'm.l', 'metri', 'metro', 'metri lineari', 'metro lineare'];

    private const CADAUNO = ['cad', 'cadauno', 'cadauna', 'n', 'nr', 'pz', 'pezzo', 'pezzi', 'corpo', 'a corpo'];

    /** 'superficie', 'lunghezza', 'cadauno' o null se l'unità non è geometrica (es. ore). */
    public static function tipoMisura(?string $unit): ?string
    {
        $u = rtrim(mb_strtolower(trim((string) $unit)), '.');
        if ($u === '') {
            return null;
        }
        if (in_array($u, self::SUPERFICIE, true)) {
            return 'superficie';
        }
        if (in_array($u, self::LUNGHEZZA, true)) {
            return 'lunghezza';
        }
        if (in_array($u, self::CADAUNO, true)) {
            return 'cadauno';
        }

        return null;
    }

    /**
     * La quantità che la geometria dell'elemento propone nell'unità indicata,
     * o null quando non c'è niente da proporre: unità non geometrica, misura
     * assente o incoerente (una linea non ha superficie, un punto non ha metri).
     */
    public static function perAsset(?string $unit, Asset $asset): ?float
    {
        return match (self::tipoMisura($unit)) {
            'superficie' => self::positiva($asset->computed_area_sqm),
            'lunghezza' => self::positiva($asset->computed_length_m),
            'cadauno' => 1.0,
            default => null,
        };
    }

    private static function positiva(mixed $valore): ?float
    {
        $v = (float) $valore;

        return $v > 0 ? round($v, 2) : null;
    }
}
