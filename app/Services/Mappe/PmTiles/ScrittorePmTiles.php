<?php

namespace App\Services\Mappe\PmTiles;

/**
 * Scrive un archivio PMTiles v3: le tessere si aggiungono in qualunque
 * ordine (si ordinano alla fine), i contenuti identici si scrivono una volta
 * sola e le ripetizioni consecutive diventano una voce con run_length, come
 * fa lo scrittore ufficiale. Il direttorio radice resta entro i primi 16 KB:
 * se le voci non ci stanno, si spostano in direttori foglia.
 */
final class ScrittorePmTiles
{
    public const RADICE_MASSIMA = LettorePmTiles::PRIMO_BLOCCO - Intestazione::LUNGHEZZA;

    /** @var array<int, array{offset:int,length:int}> per numero di tessera */
    private array $tessere = [];

    /** @var array<string,int> impronta del contenuto -> offset */
    private array $impronte = [];

    /** @var resource */
    private $dati;

    private int $offset = 0;

    private int $contenuti = 0;

    public function __construct()
    {
        $this->dati = tmpfile();
        if ($this->dati === false) {
            throw new \RuntimeException('Impossibile creare il file temporaneo dell\'archivio.');
        }
    }

    public function aggiungi(int $tileId, string $byte): void
    {
        $impronta = hash('xxh128', $byte);
        if (! isset($this->impronte[$impronta])) {
            fwrite($this->dati, $byte);
            $this->impronte[$impronta] = $this->offset;
            $this->offset += strlen($byte);
            $this->contenuti++;
        }
        $this->tessere[$tileId] = ['offset' => $this->impronte[$impronta], 'length' => strlen($byte)];
    }

    public function quante(): int
    {
        return count($this->tessere);
    }

    public function byteDati(): int
    {
        return $this->offset;
    }

    /**
     * Scrive l'archivio completo nel percorso dato.
     *
     * @param  array{tileCompression?:int,tileType?:int,riquadro?:array,centro?:array,centerZoom?:int}  $opzioni
     */
    public function finalizza(string $percorso, array $metadati = [], array $opzioni = []): Intestazione
    {
        if ($this->tessere === []) {
            throw new \RuntimeException('Nessuna tessera da scrivere.');
        }
        ksort($this->tessere, SORT_NUMERIC);

        // Le voci: le tessere consecutive con lo stesso contenuto diventano una ripetizione
        $voci = [];
        foreach ($this->tessere as $id => $t) {
            $ultima = $voci === [] ? null : $voci[count($voci) - 1];
            if ($ultima !== null && $ultima->offset === $t['offset'] && $ultima->length === $t['length'] && $id === $ultima->tileId + $ultima->runLength) {
                $voci[count($voci) - 1] = new Voce($ultima->tileId, $ultima->offset, $ultima->length, $ultima->runLength + 1);
            } else {
                $voci[] = new Voce($id, $t['offset'], $t['length'], 1);
            }
        }

        [$radice, $foglie] = self::direttori($voci);
        $metadatiByte = gzencode(json_encode($metadati ?: new \stdClass, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 9);

        $ids = array_keys($this->tessere);
        [$minZ] = IdTessera::aZxy($ids[0]);
        [$maxZ] = IdTessera::aZxy($ids[count($ids) - 1]);
        $riquadro = $opzioni['riquadro'] ?? [-180.0, -85.0, 180.0, 85.0];
        $centro = $opzioni['centro'] ?? [($riquadro[0] + $riquadro[2]) / 2, ($riquadro[1] + $riquadro[3]) / 2];

        $h = new Intestazione(
            rootOffset: Intestazione::LUNGHEZZA,
            rootLength: strlen($radice),
            metadataOffset: Intestazione::LUNGHEZZA + strlen($radice),
            metadataLength: strlen($metadatiByte),
            leafOffset: Intestazione::LUNGHEZZA + strlen($radice) + strlen($metadatiByte),
            leafLength: strlen($foglie),
            tileDataOffset: Intestazione::LUNGHEZZA + strlen($radice) + strlen($metadatiByte) + strlen($foglie),
            tileDataLength: $this->offset,
            addressedTiles: count($this->tessere),
            tileEntries: count($voci),
            tileContents: $this->contenuti,
            clustered: $this->eRaggruppato($voci),
            internalCompression: Intestazione::COMPRESSIONE_GZIP,
            tileCompression: $opzioni['tileCompression'] ?? Intestazione::COMPRESSIONE_GZIP,
            tileType: $opzioni['tileType'] ?? Intestazione::TIPO_MVT,
            minZoom: $minZ,
            maxZoom: $maxZ,
            minLon: (float) $riquadro[0], minLat: (float) $riquadro[1], maxLon: (float) $riquadro[2], maxLat: (float) $riquadro[3],
            centerZoom: (int) ($opzioni['centerZoom'] ?? min($maxZ, max($minZ, 13))),
            centerLon: (float) $centro[0], centerLat: (float) $centro[1],
        );

        $out = fopen($percorso, 'wb');
        if ($out === false) {
            throw new \RuntimeException("Impossibile scrivere l'archivio: $percorso");
        }
        fwrite($out, $h->aByte());
        fwrite($out, $radice);
        fwrite($out, $metadatiByte);
        fwrite($out, $foglie);
        rewind($this->dati);
        stream_copy_to_stream($this->dati, $out);
        fclose($out);
        fclose($this->dati);
        $this->dati = tmpfile();

        return $h;
    }

    /**
     * Radice e foglie: tutto in radice se ci sta nei primi 16 KB, altrimenti
     * foglie sempre piu' grandi finche' la radice non ci sta.
     *
     * @param  Voce[]  $voci
     * @return array{0:string,1:string}
     */
    private static function direttori(array $voci): array
    {
        $radice = gzencode(Direttorio::codifica($voci), 9);
        if (strlen($radice) <= self::RADICE_MASSIMA) {
            return [$radice, ''];
        }
        $perFoglia = 4096;
        while (true) {
            $foglie = '';
            $vociRadice = [];
            foreach (array_chunk($voci, $perFoglia) as $gruppo) {
                $byte = gzencode(Direttorio::codifica($gruppo), 9);
                $vociRadice[] = new Voce($gruppo[0]->tileId, strlen($foglie), strlen($byte), 0);
                $foglie .= $byte;
            }
            $radice = gzencode(Direttorio::codifica($vociRadice), 9);
            if (strlen($radice) <= self::RADICE_MASSIMA) {
                return [$radice, $foglie];
            }
            $perFoglia *= 2;
        }
    }

    /** @param Voce[] $voci */
    private function eRaggruppato(array $voci): bool
    {
        $ultimo = -1;
        foreach ($voci as $v) {
            if ($v->offset < $ultimo) {
                return false;
            }
            $ultimo = $v->offset;
        }

        return true;
    }
}
