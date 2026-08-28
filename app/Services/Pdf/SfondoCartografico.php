<?php

namespace App\Services\Pdf;

use Illuminate\Support\Facades\Http;

/**
 * Lo sfondo cartografico delle planimetrie: riquadri raster ({z}/{x}/{y})
 * ricomposti sull'inquadratura richiesta.
 *
 * Regola fissa: lo sfondo non deve MAI far fallire la stampa. Qualsiasi
 * intoppo (rete assente, servizio lento, risposta strana) restituisce null
 * e la planimetria esce come disegno tecnico. I riquadri scaricati si
 * conservano su disco: le ristampe non richiedono la rete.
 */
class SfondoCartografico
{
    private const MONDO = 20037508.342789244;

    private const LATO = 256;

    /** Oltre questo numero di riquadri si abbassa lo zoom: gentilezza verso il servizio. */
    private const MASSIMO_RIQUADRI = 25;

    /**
     * Al primo intoppo il servizio si spegne per il resto della stampa: se la
     * rete non risponde per la prima tavola non rispondera' nemmeno per le
     * altre, e ogni tavola in piu' sarebbe solo attesa buttata.
     */
    private bool $fuoriUso = false;

    /** Secondi gia' spesi in rete in questa stampa (la cache non conta). */
    private float $spesi = 0.0;

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox EPSG:3857 [minx, miny, maxx, maxy]
     * @return \GdImage|null l'immagine (larga $larghezza, in proporzione) o null se lo sfondo non si puo' avere
     */
    public function per(array $bbox, int $larghezza, int $altezza): ?\GdImage
    {
        if (config('planimetrie.sfondo') !== 'auto' || $this->fuoriUso) {
            return null;
        }

        [$minx, $miny, $maxx, $maxy] = $bbox;
        if ($maxx <= $minx || $maxy <= $miny) {
            return null;
        }

        // Lo zoom piu' fitto che copre l'inquadratura senza sgranare ne'
        // chiedere troppi riquadri
        $risoluzione = ($maxx - $minx) / $larghezza;   // metri di Mercatore per pixel
        $z = (int) floor(log(2 * self::MONDO / (self::LATO * $risoluzione), 2));
        $z = max(1, min(19, $z));

        do {
            $n = 2 ** $z;
            $tx0 = (int) floor(($minx + self::MONDO) / (2 * self::MONDO) * $n);
            $tx1 = (int) floor(($maxx + self::MONDO) / (2 * self::MONDO) * $n);
            $ty0 = (int) floor((self::MONDO - $maxy) / (2 * self::MONDO) * $n);
            $ty1 = (int) floor((self::MONDO - $miny) / (2 * self::MONDO) * $n);
            $quanti = ($tx1 - $tx0 + 1) * ($ty1 - $ty0 + 1);
            if ($quanti <= self::MASSIMO_RIQUADRI) {
                break;
            }
            $z--;
        } while ($z > 1);

        $mosaico = imagecreatetruecolor(($tx1 - $tx0 + 1) * self::LATO, ($ty1 - $ty0 + 1) * self::LATO);
        for ($tx = $tx0; $tx <= $tx1; $tx++) {
            for ($ty = $ty0; $ty <= $ty1; $ty++) {
                // Tetto complessivo: una rete lenta ma viva non deve tenere
                // in ostaggio la stampa per minuti
                if ($this->spesi > (float) config('planimetrie.tempo_massimo', 20)) {
                    $this->fuoriUso = true;
                    imagedestroy($mosaico);

                    return null;
                }
                $riquadro = $this->riquadro($z, $tx, $ty);
                if ($riquadro === null) {
                    $this->fuoriUso = true;
                    imagedestroy($mosaico);

                    return null;
                }
                imagecopy($mosaico, $riquadro, ($tx - $tx0) * self::LATO, ($ty - $ty0) * self::LATO, 0, 0, self::LATO, self::LATO);
                imagedestroy($riquadro);
            }
        }

        // Ritaglio dell'inquadratura esatta e riscalatura alla misura chiesta
        $unMetro = self::LATO * $n / (2 * self::MONDO);   // pixel del mosaico per metro di Mercatore
        $sx = ($minx + self::MONDO) * $unMetro - $tx0 * self::LATO;
        $sy = (self::MONDO - $maxy) * $unMetro - $ty0 * self::LATO;
        $sw = ($maxx - $minx) * $unMetro;
        $sh = ($maxy - $miny) * $unMetro;

        $fuori = imagecreatetruecolor($larghezza, $altezza);
        imagecopyresampled($fuori, $mosaico, 0, 0, (int) round($sx), (int) round($sy), $larghezza, $altezza, (int) round($sw), (int) round($sh));
        imagedestroy($mosaico);

        // Un filo piu' chiaro: il disegno del verde deve restare protagonista
        imagefilter($fuori, IMG_FILTER_BRIGHTNESS, 22);

        return $fuori;
    }

    /** Un riquadro, dalla cache su disco o dalla rete. */
    private function riquadro(int $z, int $x, int $y): ?\GdImage
    {
        // La cache e' per fornitore: cambiando l'indirizzo dei riquadri non
        // si mischiano immagini di servizi diversi
        $fornitore = substr(md5((string) config('planimetrie.tile_url')), 0, 12);
        $base = config('planimetrie.cache_dir') ?: storage_path('app/planimetrie-tiles');
        $percorso = "{$base}/{$fornitore}/{$z}/{$x}/{$y}.png";
        $ttl = ((int) config('planimetrie.cache_giorni', 30)) * 86400;
        if (is_file($percorso) && filemtime($percorso) > time() - $ttl) {
            $img = @imagecreatefromstring((string) file_get_contents($percorso));
            if ($img !== false) {
                return $img;
            }
        }

        $url = str_replace(['{z}', '{x}', '{y}'],
            [(string) $z, (string) $x, (string) $y],
            (string) config('planimetrie.tile_url'));

        $inizio = microtime(true);
        try {
            $risposta = Http::timeout((int) config('planimetrie.timeout', 4))
                ->withHeaders(['User-Agent' => 'WebGIS-Censimento/1.0 (stampa scheda localita)'])
                ->get($url);
        } catch (\Throwable) {
            return null;
        } finally {
            $this->spesi += microtime(true) - $inizio;
        }
        if (! $risposta->successful()) {
            return null;
        }

        $corpo = $risposta->body();
        $img = @imagecreatefromstring($corpo);
        if ($img === false) {
            return null;
        }

        @mkdir(dirname($percorso), 0775, true);
        @file_put_contents($percorso, $corpo);

        // I riquadri possono arrivare a tavolozza indicizzata: il mosaico
        // lavora in truecolor
        imagepalettetotruecolor($img);

        return $img;
    }
}
