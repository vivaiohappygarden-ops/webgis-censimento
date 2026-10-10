<?php

namespace App\Services\Mappe\PmTiles;

/**
 * Numerazione delle tessere del formato PMTiles: ogni tessera z/x/y ha un
 * numero unico, in ordine di curva di Hilbert dentro ogni zoom (le tessere
 * vicine sulla mappa restano vicine nel file). Gli algoritmi sono quelli
 * della specifica (v3), verificati contro la libreria ufficiale.
 */
final class IdTessera
{
    public const ZOOM_MASSIMO = 26;

    public static function daZxy(int $z, int $x, int $y): int
    {
        if ($z < 0 || $z > self::ZOOM_MASSIMO) {
            throw new \InvalidArgumentException("Zoom $z fuori dall'intervallo ammesso (0-".self::ZOOM_MASSIMO.').');
        }
        $n = 1 << $z;
        if ($x < 0 || $y < 0 || $x >= $n || $y >= $n) {
            throw new \InvalidArgumentException("Tessera $z/$x/$y fuori dai limiti dello zoom.");
        }

        // Le tessere di tutti gli zoom precedenti: 1 + 4 + 16 + ... = (4^z - 1) / 3
        $id = intdiv((1 << (2 * $z)) - 1, 3);
        for ($a = $z - 1; $a >= 0; $a--) {
            $s = 1 << $a;
            $rx = $s & $x;
            $ry = $s & $y;
            $id += ((3 * $rx) ^ $ry) << $a;
            [$x, $y] = self::ruota($s, $x, $y, $rx, $ry);
        }

        return $id;
    }

    /** @return array{0:int,1:int,2:int} [z, x, y] */
    public static function aZxy(int $id): array
    {
        if ($id < 0) {
            throw new \InvalidArgumentException('Numero di tessera negativo.');
        }
        // Lo zoom: il primo per cui (4^(z+1) - 1) / 3 supera il numero
        $z = 0;
        $base = 0;
        while (true) {
            $prossima = intdiv((1 << (2 * ($z + 1))) - 1, 3);
            if ($id < $prossima) {
                break;
            }
            $base = $prossima;
            $z++;
            if ($z > self::ZOOM_MASSIMO) {
                throw new \InvalidArgumentException('Numero di tessera oltre lo zoom massimo.');
            }
        }

        $t = $id - $base;
        $n = 1 << $z;
        $x = 0;
        $y = 0;
        for ($s = 1; $s < $n; $s <<= 1) {
            $rx = 1 & intdiv($t, 2);
            $ry = 1 & ($t ^ $rx);
            [$x, $y] = self::ruota($s, $x, $y, $rx, $ry);
            $x += $s * $rx;
            $y += $s * $ry;
            $t = intdiv($t, 4);
        }

        return [$z, $x, $y];
    }

    /** @return array{0:int,1:int} */
    private static function ruota(int $n, int $x, int $y, int $rx, int $ry): array
    {
        if ($ry === 0) {
            if ($rx !== 0) {
                $x = $n - 1 - $x;
                $y = $n - 1 - $y;
            }

            return [$y, $x];
        }

        return [$x, $y];
    }

    /** La colonna x di una longitudine allo zoom dato. */
    public static function colonna(float $lon, int $z): int
    {
        $n = 1 << $z;
        $x = (int) floor(($lon + 180.0) / 360.0 * $n);

        return max(0, min($n - 1, $x));
    }

    /** La riga y di una latitudine allo zoom dato (proiezione di Mercatore). */
    public static function riga(float $lat, int $z): int
    {
        $n = 1 << $z;
        $lat = max(-85.05112878, min(85.05112878, $lat));
        $rad = deg2rad($lat);
        $y = (int) floor((1.0 - log(tan($rad) + 1.0 / cos($rad)) / M_PI) / 2.0 * $n);

        return max(0, min($n - 1, $y));
    }

    /**
     * Le tessere di uno zoom che coprono un riquadro [minLon, minLat, maxLon, maxLat].
     *
     * @return array{x0:int,x1:int,y0:int,y1:int}
     */
    public static function intervallo(array $riquadro, int $z): array
    {
        [$minLon, $minLat, $maxLon, $maxLat] = $riquadro;

        return [
            'x0' => self::colonna($minLon, $z),
            'x1' => self::colonna($maxLon, $z),
            'y0' => self::riga($maxLat, $z),
            'y1' => self::riga($minLat, $z),
        ];
    }

    /** Quante tessere coprono il riquadro fra due zoom. */
    public static function conta(array $riquadro, int $zoomMin, int $zoomMax): int
    {
        $totale = 0;
        for ($z = $zoomMin; $z <= $zoomMax; $z++) {
            $i = self::intervallo($riquadro, $z);
            $totale += ($i['x1'] - $i['x0'] + 1) * ($i['y1'] - $i['y0'] + 1);
        }

        return $totale;
    }
}
