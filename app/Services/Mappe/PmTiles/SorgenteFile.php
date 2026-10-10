<?php

namespace App\Services\Mappe\PmTiles;

final class SorgenteFile implements SorgenteByte
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $percorso)
    {
        if (! is_file($percorso)) {
            throw new \RuntimeException("Archivio PMTiles non trovato: $percorso");
        }
    }

    public function leggi(int $offset, int $lunghezza): string
    {
        if ($lunghezza <= 0) {
            return '';
        }
        $this->handle ??= fopen($this->percorso, 'rb');
        if ($this->handle === false) {
            throw new \RuntimeException("Impossibile aprire l'archivio: {$this->percorso}");
        }
        fseek($this->handle, $offset);
        $dati = fread($this->handle, $lunghezza);

        return $dati === false ? '' : $dati;
    }

    public function chiave(): string
    {
        return $this->percorso;
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }
}
