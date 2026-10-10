<?php

namespace App\Services\Mappe\PmTiles;

use Illuminate\Support\Facades\Http;

/**
 * Un archivio PMTiles raggiungibile in rete, letto a intervalli (richieste
 * HTTP con l'intestazione Range): del pianeta intero (oltre 100 GB) si
 * scaricano solo l'intestazione, i direttori che servono e le tessere del
 * territorio. Il server deve rispondere 206 all'intervallo chiesto: una
 * risposta 200 con il file intero sarebbe un disastro, e si ferma subito.
 */
final class SorgenteHttp implements SorgenteByte
{
    public function __construct(
        private readonly string $url,
        private readonly int $timeout = 120,
        private readonly int $tentativi = 3,
    ) {}

    public function leggi(int $offset, int $lunghezza): string
    {
        if ($lunghezza <= 0) {
            return '';
        }
        $fine = $offset + $lunghezza - 1;
        $risposta = Http::withHeaders(['Range' => "bytes=$offset-$fine"])
            ->timeout($this->timeout)
            ->retry($this->tentativi, 1500, throw: false)
            ->get($this->url);

        if ($risposta->status() === 206) {
            $dati = $risposta->body();
            if (strlen($dati) > $lunghezza) {
                throw new \RuntimeException("La sorgente ha risposto con piu' byte di quelli chiesti ($offset-$fine).");
            }

            return $dati;
        }
        if ($risposta->status() === 200) {
            $dati = $risposta->body();
            // Un file intero piu' corto dell'intervallo chiesto e' un file piccolo letto tutto: va bene
            if (strlen($dati) <= $lunghezza + $offset) {
                return substr($dati, $offset, $lunghezza);
            }
            throw new \RuntimeException('La sorgente non accetta le richieste a intervalli (Range): servirebbe il file intero.');
        }

        throw new \RuntimeException("La sorgente ha risposto {$risposta->status()} all'intervallo $offset-$fine.");
    }

    public function chiave(): string
    {
        return $this->url;
    }
}
