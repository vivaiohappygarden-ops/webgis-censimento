<?php

namespace App\Services\Mappe\PmTiles;

/**
 * Ritaglia da un archivio PMTiles (il pianeta) le sole tessere che coprono
 * un riquadro, dallo zoom minimo a quello chiesto, e le scrive in un nuovo
 * archivio. Legge i byte a intervalli contigui, cosi' dal pianeta in rete
 * arrivano poche richieste grandi invece di una per tessera.
 */
final class Estrattore
{
    /** Due tessere a meno di tanti byte si leggono con una sola richiesta */
    public const SALTO_MASSIMO = 65536;

    /** Una richiesta non supera questi byte */
    public const BLOCCO_MASSIMO = 8 * 1024 * 1024;

    /**
     * @param  array{0:float,1:float,2:float,3:float}  $riquadro  [minLon, minLat, maxLon, maxLat]
     * @param  callable(string):void|null  $avanzamento
     * @return array{tessere:int,byte:int,zoom_min:int,zoom_max:int,riquadro:array}
     */
    public function estrai(LettorePmTiles $sorgente, array $riquadro, int $zoomMax, string $destinazione, int $tettoTessere = 5000, ?callable $avanzamento = null): array
    {
        $h = $sorgente->intestazione();
        $zoomMin = $h->minZoom;
        $zoomMax = min($zoomMax, $h->maxZoom);
        if ($zoomMax < $zoomMin) {
            throw new \RuntimeException("La sorgente copre gli zoom {$h->minZoom}-{$h->maxZoom}, non lo zoom chiesto.");
        }
        // Il riquadro non esce da quello della sorgente
        $riquadro = [
            max($riquadro[0], $h->minLon), max($riquadro[1], $h->minLat),
            min($riquadro[2], $h->maxLon), min($riquadro[3], $h->maxLat),
        ];
        if ($riquadro[0] >= $riquadro[2] || $riquadro[1] >= $riquadro[3]) {
            throw new \RuntimeException('Il territorio sta fuori dall\'area coperta dalla sorgente.');
        }
        $quante = IdTessera::conta($riquadro, $zoomMin, $zoomMax);
        if ($quante > $tettoTessere) {
            throw new \RuntimeException("Il territorio richiede $quante tessere fino allo zoom $zoomMax, oltre il tetto di $tettoTessere: riduci lo zoom o il margine.");
        }

        // 1) Le voci di tutte le tessere nel riquadro
        $voci = [];
        $mancanti = 0;
        for ($z = $zoomMin; $z <= $zoomMax; $z++) {
            $i = IdTessera::intervallo($riquadro, $z);
            for ($x = $i['x0']; $x <= $i['x1']; $x++) {
                for ($y = $i['y0']; $y <= $i['y1']; $y++) {
                    $id = IdTessera::daZxy($z, $x, $y);
                    $v = $sorgente->voce($id);
                    if ($v === null) {
                        $mancanti++;

                        continue;
                    }
                    $voci[$id] = $v;
                }
            }
            if ($avanzamento) {
                $avanzamento("zoom $z: ".count($voci).' tessere trovate');
            }
        }
        if ($voci === []) {
            throw new \RuntimeException('Nessuna tessera della sorgente copre il territorio.');
        }

        // 2) Gli intervalli di byte da leggere, uniti quando sono vicini
        $intervalli = [];
        foreach ($voci as $v) {
            $intervalli[$v->offset] = max($intervalli[$v->offset] ?? 0, $v->length);
        }
        ksort($intervalli, SORT_NUMERIC);
        $blocchi = [];
        foreach ($intervalli as $offset => $lunghezza) {
            $ultimo = $blocchi === [] ? null : $blocchi[count($blocchi) - 1];
            $fine = $offset + $lunghezza;
            if ($ultimo !== null && $offset - $ultimo['fine'] <= self::SALTO_MASSIMO && $fine - $ultimo['inizio'] <= self::BLOCCO_MASSIMO) {
                $blocchi[count($blocchi) - 1]['fine'] = max($ultimo['fine'], $fine);
            } else {
                $blocchi[] = ['inizio' => $offset, 'fine' => $fine];
            }
        }

        // 3) Lettura a blocchi e scrittura: ogni voce pesca i suoi byte dal blocco
        $perBlocco = [];
        foreach ($voci as $id => $v) {
            foreach ($blocchi as $k => $b) {
                if ($v->offset >= $b['inizio'] && $v->offset + $v->length <= $b['fine']) {
                    $perBlocco[$k][] = $id;
                    break;
                }
            }
        }
        $scrittore = new ScrittorePmTiles;
        $byteLetti = 0;
        $base = $h->tileDataOffset;
        foreach ($blocchi as $k => $b) {
            $byte = $sorgente->sorgente()->leggi($base + $b['inizio'], $b['fine'] - $b['inizio']);
            if (strlen($byte) < $b['fine'] - $b['inizio']) {
                throw new \RuntimeException('La sorgente ha restituito meno byte di quelli chiesti: estrazione interrotta.');
            }
            $byteLetti += strlen($byte);
            foreach ($perBlocco[$k] ?? [] as $id) {
                $v = $voci[$id];
                $contenuto = substr($byte, $v->offset - $b['inizio'], $v->length);
                // Una voce con ripetizioni copre piu' tessere: si scrivono tutte quelle nel riquadro
                $scrittore->aggiungi($id, $contenuto);
            }
            if ($avanzamento) {
                $avanzamento('letti '.number_format($byteLetti / 1048576, 1, ',', '.').' MB ('.($k + 1).'/'.count($blocchi).' blocchi)');
            }
        }

        $metadati = $sorgente->metadati();
        $metadati['name'] = ($metadati['name'] ?? 'Sfondo').' (estratto)';
        $metadati['bounds'] = implode(',', $riquadro);
        $intestazione = $scrittore->finalizza($destinazione, $metadati, [
            'tileCompression' => $h->tileCompression,
            'tileType' => $h->tileType,
            'riquadro' => $riquadro,
        ]);

        return [
            'tessere' => $intestazione->addressedTiles,
            'contenuti' => $intestazione->tileContents,
            'mancanti' => $mancanti,
            'byte' => filesize($destinazione),
            'zoom_min' => $intestazione->minZoom,
            'zoom_max' => $intestazione->maxZoom,
            'riquadro' => $riquadro,
        ];
    }
}
