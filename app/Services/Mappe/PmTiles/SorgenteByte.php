<?php

namespace App\Services\Mappe\PmTiles;

/** Da dove si leggono i byte di un archivio: un file sul disco o un indirizzo con le richieste a intervalli. */
interface SorgenteByte
{
    /** Legge al massimo $lunghezza byte dalla posizione data (meno solo a fine file). */
    public function leggi(int $offset, int $lunghezza): string;

    public function chiave(): string;
}
