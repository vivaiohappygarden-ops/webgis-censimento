<?php

namespace App\Services\Mappe\PmTiles;

/**
 * Una voce di un direttorio PMTiles: da quale numero di tessera parte, dove
 * stanno i byte (rispetto alla sezione dei dati o a quella dei direttori
 * foglia), quanto sono lunghi e quante tessere consecutive identiche copre.
 * runLength 0 vuol dire "qui c'e' un direttorio foglia, non una tessera".
 */
final class Voce
{
    public function __construct(
        public readonly int $tileId,
        public readonly int $offset,
        public readonly int $length,
        public readonly int $runLength = 1,
    ) {}

    public function eFoglia(): bool
    {
        return $this->runLength === 0;
    }

    public function copre(int $tileId): bool
    {
        return $this->runLength > 0 && $tileId >= $this->tileId && $tileId < $this->tileId + $this->runLength;
    }
}
