<?php

namespace App\Services\Mappe\PmTiles;

/**
 * L'intestazione di 127 byte di un archivio PMTiles v3: dove stanno il
 * direttorio radice, i metadati, le foglie e i dati delle tessere, i
 * conteggi, le compressioni, il tipo di tessera, gli zoom e il riquadro.
 */
final class Intestazione
{
    public const LUNGHEZZA = 127;

    public const COMPRESSIONE_NESSUNA = 1;

    public const COMPRESSIONE_GZIP = 2;

    public const TIPO_MVT = 1;

    public function __construct(
        public int $rootOffset = 0,
        public int $rootLength = 0,
        public int $metadataOffset = 0,
        public int $metadataLength = 0,
        public int $leafOffset = 0,
        public int $leafLength = 0,
        public int $tileDataOffset = 0,
        public int $tileDataLength = 0,
        public int $addressedTiles = 0,
        public int $tileEntries = 0,
        public int $tileContents = 0,
        public bool $clustered = false,
        public int $internalCompression = self::COMPRESSIONE_GZIP,
        public int $tileCompression = self::COMPRESSIONE_GZIP,
        public int $tileType = self::TIPO_MVT,
        public int $minZoom = 0,
        public int $maxZoom = 0,
        public float $minLon = -180.0,
        public float $minLat = -85.0,
        public float $maxLon = 180.0,
        public float $maxLat = 85.0,
        public int $centerZoom = 0,
        public float $centerLon = 0.0,
        public float $centerLat = 0.0,
    ) {}

    public static function daByte(string $byte): self
    {
        if (strlen($byte) < self::LUNGHEZZA || substr($byte, 0, 7) !== 'PMTiles') {
            throw new \RuntimeException('Il file non e\' un archivio PMTiles.');
        }
        $versione = ord($byte[7]);
        if ($versione !== 3) {
            throw new \RuntimeException("Archivio PMTiles di versione $versione: il programma legge la versione 3.");
        }
        $u64 = fn (int $p): int => unpack('P', substr($byte, $p, 8))[1];
        $i32 = fn (int $p): int => unpack('l', substr($byte, $p, 4))[1];

        return new self(
            rootOffset: $u64(8), rootLength: $u64(16), metadataOffset: $u64(24), metadataLength: $u64(32),
            leafOffset: $u64(40), leafLength: $u64(48), tileDataOffset: $u64(56), tileDataLength: $u64(64),
            addressedTiles: $u64(72), tileEntries: $u64(80), tileContents: $u64(88),
            clustered: ord($byte[96]) === 1, internalCompression: ord($byte[97]), tileCompression: ord($byte[98]),
            tileType: ord($byte[99]), minZoom: ord($byte[100]), maxZoom: ord($byte[101]),
            minLon: $i32(102) / 1e7, minLat: $i32(106) / 1e7, maxLon: $i32(110) / 1e7, maxLat: $i32(114) / 1e7,
            centerZoom: ord($byte[118]), centerLon: $i32(119) / 1e7, centerLat: $i32(123) / 1e7,
        );
    }

    public function aByte(): string
    {
        $e7 = fn (float $v): string => pack('l', (int) round($v * 1e7));

        return 'PMTiles'.chr(3)
            .pack('P', $this->rootOffset).pack('P', $this->rootLength)
            .pack('P', $this->metadataOffset).pack('P', $this->metadataLength)
            .pack('P', $this->leafOffset).pack('P', $this->leafLength)
            .pack('P', $this->tileDataOffset).pack('P', $this->tileDataLength)
            .pack('P', $this->addressedTiles).pack('P', $this->tileEntries).pack('P', $this->tileContents)
            .chr($this->clustered ? 1 : 0).chr($this->internalCompression).chr($this->tileCompression).chr($this->tileType)
            .chr($this->minZoom).chr($this->maxZoom)
            .$e7($this->minLon).$e7($this->minLat).$e7($this->maxLon).$e7($this->maxLat)
            .chr($this->centerZoom).$e7($this->centerLon).$e7($this->centerLat);
    }

    /** @return array{0:float,1:float,2:float,3:float} */
    public function riquadro(): array
    {
        return [$this->minLon, $this->minLat, $this->maxLon, $this->maxLat];
    }
}
