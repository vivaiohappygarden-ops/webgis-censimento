<?php

namespace App\Services\Mappe\PmTiles;

/**
 * Legge un archivio PMTiles v3 da una sorgente a intervalli: intestazione e
 * direttorio radice in una sola lettura (stanno nei primi 16 KB per
 * specifica), i direttori foglia quando servono, le tessere una per volta o
 * a blocchi. Scritto a mano perche' il formato e' semplice e documentato e
 * il server non deve dipendere da un programma esterno.
 */
final class LettorePmTiles
{
    public const PRIMO_BLOCCO = 16384;

    private ?Intestazione $intestazione = null;

    /** @var Voce[]|null */
    private ?array $radice = null;

    /** @var array<string, Voce[]> direttori foglia gia' letti, per offset|lunghezza */
    private array $foglie = [];

    public function __construct(private readonly SorgenteByte $sorgente) {}

    public function sorgente(): SorgenteByte
    {
        return $this->sorgente;
    }

    public function intestazione(): Intestazione
    {
        $this->carica();

        return $this->intestazione;
    }

    /** @return Voce[] */
    public function radice(): array
    {
        $this->carica();

        return $this->radice;
    }

    public function metadati(): array
    {
        $h = $this->intestazione();
        if ($h->metadataLength === 0) {
            return [];
        }
        $byte = self::decomprimi($this->sorgente->leggi($h->metadataOffset, $h->metadataLength), $h->internalCompression);

        return json_decode($byte, true) ?: [];
    }

    /** La voce che contiene la tessera (gia' risolta attraverso le foglie), o null se non c'e'. */
    public function voce(int $tileId): ?Voce
    {
        $h = $this->intestazione();
        $voci = $this->radice();
        for ($profondita = 0; $profondita <= 3; $profondita++) {
            $v = Direttorio::trova($voci, $tileId);
            if ($v === null) {
                return null;
            }
            if (! $v->eFoglia()) {
                return $v;
            }
            $voci = $this->foglia($h->leafOffset + $v->offset, $v->length);
        }
        throw new \RuntimeException('Direttori PMTiles annidati oltre il limite.');
    }

    /** I byte della tessera come stanno nel file (compressi come dice l'intestazione), o null. */
    public function tessera(int $z, int $x, int $y): ?string
    {
        $v = $this->voce(IdTessera::daZxy($z, $x, $y));
        if ($v === null) {
            return null;
        }

        return $this->sorgente->leggi($this->intestazione()->tileDataOffset + $v->offset, $v->length);
    }

    /** La tessera decompressa (per i test e i controlli). */
    public function tesseraDecompressa(int $z, int $x, int $y): ?string
    {
        $byte = $this->tessera($z, $x, $y);

        return $byte === null ? null : self::decomprimi($byte, $this->intestazione()->tileCompression);
    }

    public static function decomprimi(string $byte, int $compressione): string
    {
        return match ($compressione) {
            Intestazione::COMPRESSIONE_NESSUNA, 0 => $byte,
            Intestazione::COMPRESSIONE_GZIP => self::gunzip($byte),
            default => throw new \RuntimeException("Compressione PMTiles $compressione non gestita (solo gzip o nessuna)."),
        };
    }

    private static function gunzip(string $byte): string
    {
        $out = @gzdecode($byte);
        if ($out === false) {
            throw new \RuntimeException('Blocco gzip dell\'archivio PMTiles illeggibile.');
        }

        return $out;
    }

    private function carica(): void
    {
        if ($this->intestazione !== null) {
            return;
        }
        $primo = $this->sorgente->leggi(0, self::PRIMO_BLOCCO);
        $h = Intestazione::daByte($primo);
        if ($h->rootOffset + $h->rootLength <= strlen($primo)) {
            $byteRadice = substr($primo, $h->rootOffset, $h->rootLength);
        } else {
            // Fuori specifica ma possibile: la radice oltre i primi 16 KB
            $byteRadice = $this->sorgente->leggi($h->rootOffset, $h->rootLength);
        }
        $this->radice = Direttorio::decodifica(self::decomprimi($byteRadice, $h->internalCompression));
        $this->intestazione = $h;
    }

    /** @return Voce[] */
    private function foglia(int $offset, int $lunghezza): array
    {
        $chiave = "$offset|$lunghezza";
        if (! isset($this->foglie[$chiave])) {
            $byte = $this->sorgente->leggi($offset, $lunghezza);
            $voci = Direttorio::decodifica(self::decomprimi($byte, $this->intestazione->internalCompression));
            if ($voci === []) {
                throw new \RuntimeException('Direttorio foglia vuoto nell\'archivio PMTiles.');
            }
            // Tetto alla memoria: le foglie del pianeta sono tante, qui ne servono poche alla volta
            if (count($this->foglie) >= 64) {
                array_shift($this->foglie);
            }
            $this->foglie[$chiave] = $voci;
        }

        return $this->foglie[$chiave];
    }
}
