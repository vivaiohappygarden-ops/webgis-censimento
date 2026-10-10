<?php

namespace App\Services\Mappe\PmTiles;

/**
 * Codifica e decodifica dei direttori PMTiles (specifica v3): il numero di
 * voci, poi i numeri di tessera come differenze dal precedente, poi le
 * ripetizioni, poi le lunghezze, poi gli offset (0 = subito dopo la voce
 * precedente), tutti come varint. La compressione (gzip) la applica chi
 * scrive e la toglie chi legge, fuori da questa classe.
 */
final class Direttorio
{
    /** @return Voce[] */
    public static function decodifica(string $byte): array
    {
        $pos = 0;
        $n = self::leggiVarint($byte, $pos);
        $ids = [];
        $ultimo = 0;
        for ($i = 0; $i < $n; $i++) {
            $ultimo += self::leggiVarint($byte, $pos);
            $ids[$i] = $ultimo;
        }
        $run = [];
        for ($i = 0; $i < $n; $i++) {
            $run[$i] = self::leggiVarint($byte, $pos);
        }
        $len = [];
        for ($i = 0; $i < $n; $i++) {
            $len[$i] = self::leggiVarint($byte, $pos);
        }
        $voci = [];
        for ($i = 0; $i < $n; $i++) {
            $off = self::leggiVarint($byte, $pos);
            if ($off === 0 && $i > 0) {
                $offset = $voci[$i - 1]->offset + $voci[$i - 1]->length;
            } else {
                $offset = $off - 1;
            }
            $voci[$i] = new Voce($ids[$i], $offset, $len[$i], $run[$i]);
        }

        return $voci;
    }

    /** @param Voce[] $voci gia' ordinate per tileId */
    public static function codifica(array $voci): string
    {
        $voci = array_values($voci);
        $out = self::varint(count($voci));
        $ultimo = 0;
        foreach ($voci as $v) {
            $out .= self::varint($v->tileId - $ultimo);
            $ultimo = $v->tileId;
        }
        foreach ($voci as $v) {
            $out .= self::varint($v->runLength);
        }
        foreach ($voci as $v) {
            $out .= self::varint($v->length);
        }
        foreach ($voci as $i => $v) {
            if ($i > 0 && $v->offset === $voci[$i - 1]->offset + $voci[$i - 1]->length) {
                $out .= self::varint(0);
            } else {
                $out .= self::varint($v->offset + 1);
            }
        }

        return $out;
    }

    /** Cerca la voce che copre il numero di tessera (o la foglia in cui cercarlo). */
    public static function trova(array $voci, int $tileId): ?Voce
    {
        $lo = 0;
        $hi = count($voci) - 1;
        while ($lo <= $hi) {
            $m = ($lo + $hi) >> 1;
            $d = $tileId - $voci[$m]->tileId;
            if ($d > 0) {
                $lo = $m + 1;
            } elseif ($d < 0) {
                $hi = $m - 1;
            } else {
                return $voci[$m];
            }
        }
        if ($hi >= 0) {
            $v = $voci[$hi];
            if ($v->runLength === 0 || $tileId - $v->tileId < $v->runLength) {
                return $v;
            }
        }

        return null;
    }

    public static function leggiVarint(string $byte, int &$pos): int
    {
        $valore = 0;
        $shift = 0;
        $lunghezza = strlen($byte);
        while (true) {
            if ($pos >= $lunghezza) {
                throw new \RuntimeException('Direttorio PMTiles troncato.');
            }
            $b = ord($byte[$pos++]);
            $valore |= ($b & 0x7F) << $shift;
            if ($b < 0x80) {
                return $valore;
            }
            $shift += 7;
            if ($shift > 63) {
                throw new \RuntimeException('Varint troppo lungo nel direttorio PMTiles.');
            }
        }
    }

    public static function varint(int $valore): string
    {
        if ($valore < 0) {
            throw new \InvalidArgumentException('Un varint non puo\' essere negativo.');
        }
        $out = '';
        while ($valore >= 0x80) {
            $out .= chr(($valore & 0x7F) | 0x80);
            $valore >>= 7;
        }

        return $out.chr($valore);
    }
}
