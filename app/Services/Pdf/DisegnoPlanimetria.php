<?php

namespace App\Services\Pdf;

/**
 * Il disegno di una planimetria: perimetri, prati, siepi e alberi con la
 * chioma sagomata, sopra lo sfondo cartografico o su carta tecnica.
 *
 * Tutte le coordinate arrivano in EPSG:3857; le misure vere si ottengono col
 * coseno della latitudine (fattore di scala di Mercatore), lo stesso conto
 * della mappa a video. Il disegno avviene a scala doppia e poi si riduce:
 * e' il modo semplice per avere curve morbide con GD.
 *
 * Le chiome non sono cerchi: il contorno e' una gobba lobata generata dal
 * codice dell'elemento (sempre uguale per lo stesso albero, diversa fra
 * alberi), con una velatura di luce verso nord-ovest. Un albero senza
 * diametro misurato esce col simbolo convenzionale piccolo e piu' tenue.
 */
class DisegnoPlanimetria
{
    private const SS = 2;                    // sovracampionamento

    private const CHIOMA_CONVENZIONALE_M = 2.5;

    private \GdImage $im;

    private array $bbox;                     // [minx, miny, maxx, maxy] 3857

    private float $scala;                    // pixel (grandi) per unita' 3857

    private int $w = 0;

    private int $h = 0;

    private float $cosLat = 1.0;

    private bool $conSfondo = false;

    /** @var list<array{0: float, 1: float, 2: float, 3: float}> rettangoli delle etichette gia' scritte */
    private array $etichetteOccupate = [];

    public function __construct(private SfondoCartografico $sfondo) {}

    /**
     * La planimetria di una singola area, zoomata sul suo perimetro.
     *
     * @return array{png: string, sfondo: bool}
     */
    public function area(array $area): array
    {
        // L'inquadratura comprende le geometrie assegnate all'area, anche
        // quelle poco oltre il perimetro (una siepe sul confine deve entrare
        // nel foglio). MA un elemento con coordinate LONTANE — un errore di
        // rilievo, un punto creato con la mappa altrove — non deve ridurre
        // l'area a un francobollo su mezza regione: resta fuori
        // dall'inquadratura e la didascalia lo dichiara.
        $punti = [];
        foreach ($area['anelli'] as $anello) {
            $punti = array_merge($punti, $anello);
        }
        $xs = array_column($punti, 0);
        $ys = array_column($punti, 1);
        $diagonale = hypot(max($xs) - min($xs), max($ys) - min($ys));
        $soglia = max($diagonale * 0.5, 60 / max(cos(deg2rad($area['lat'])), 0.01));
        $recinto = [min($xs) - $soglia, min($ys) - $soglia, max($xs) + $soglia, max($ys) + $soglia];
        $dentro = fn (array $p): bool => $p[0] >= $recinto[0] && $p[0] <= $recinto[2]
            && $p[1] >= $recinto[1] && $p[1] <= $recinto[3];

        $fuori = 0;
        $margineChiome = 0.0;
        foreach ($area['elementi'] as $e) {
            $vicini = array_values(array_filter(self::verticiDi($e), $dentro));
            if ($vicini === []) {
                $fuori++;

                continue;
            }
            $punti = array_merge($punti, $vicini);
            if (in_array($e['tipo'], ['POINT', 'MULTIPOINT'], true)) {
                $margineChiome = max($margineChiome, (float) ($e['chioma_m'] ?? 0) / 2);
            }
        }

        $this->prepara($punti, $area['lat'], $margineChiome);

        $this->disegnaAnelli($area['anelli'], fill: true);
        foreach ($area['elementi'] as $e) {
            $this->disegnaElemento($e);
        }
        $conEtichette = count($area['elementi']) <= (int) config('planimetrie.massimo_etichette', 40);
        if ($conEtichette) {
            foreach ($area['elementi'] as $e) {
                $this->etichetta($e);
            }
        }

        $this->cartiglio();

        return [
            'png' => $this->chiudi(),
            'sfondo' => $this->conSfondo,
            'etichette' => $conEtichette,
            'fuori_inquadratura' => $fuori,
        ];
    }

    /**
     * Il quadro d'insieme: tutte le aree numerate e, se disegnato, il
     * perimetro della località tratteggiato.
     *
     * @return array{png: string, sfondo: bool}
     */
    public function quadro(array $dati): array
    {
        $punti = [];
        foreach ($dati['aree'] as $area) {
            foreach ($area['anelli'] as $anello) {
                $punti = array_merge($punti, $anello);
            }
        }
        foreach ($dati['perimetro'] ?? [] as $anello) {
            $punti = array_merge($punti, $anello);
        }

        $this->prepara($punti, $dati['lat'], 0.0, altezzaMassima: 700);

        foreach ($dati['perimetro'] ?? [] as $anello) {
            $this->tratteggio($anello);
        }
        foreach ($dati['aree'] as $area) {
            $this->disegnaAnelli($area['anelli'], fill: true);
        }
        foreach ($dati['aree'] as $area) {
            $this->numeroArea($area['centro'], (string) $area['numero']);
        }

        $this->cartiglio();

        return ['png' => $this->chiudi(), 'sfondo' => $this->conSfondo];
    }

    /**
     * Tutti i vertici di un elemento, qualunque sia la sua geometria.
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function verticiDi(array $e): array
    {
        $geo = $e['geo'];

        return match ($e['tipo']) {
            'POINT' => [$geo['coordinates']],
            'MULTIPOINT' => $geo['coordinates'],
            'LINESTRING' => $geo['coordinates'],
            'MULTILINESTRING' => array_merge(...$geo['coordinates']),
            'POLYGON', 'MULTIPOLYGON' => array_merge(...\App\Services\Territorio\PlanimetriaDati::anelli($geo)),
            default => [],
        };
    }

    // --- Impianto -----------------------------------------------------------

    /** @param list<array{0: float, 1: float}> $punti */
    private function prepara(array $punti, float $lat, float $margineChiomeM, int $altezzaMassima = 1300): void
    {
        $this->cosLat = cos(deg2rad($lat));

        $xs = array_column($punti, 0);
        $ys = array_column($punti, 1);
        $minx = min($xs);
        $maxx = max($xs);
        $miny = min($ys);
        $maxy = max($ys);

        // Respiro attorno al perimetro: il 10% per lato, mai meno delle
        // chiome che sporgono e mai meno di 8 metri
        $margine = max(($maxx - $minx) * 0.10, ($maxy - $miny) * 0.10,
            ($margineChiomeM + 8) / max($this->cosLat, 0.01));
        $minx -= $margine;
        $maxx += $margine;
        $miny -= $margine;
        $maxy += $margine;

        // Un'inquadratura minima di ~170 metri: un'area piccola senza le
        // strade e le case attorno e' un poligono che galleggia nel nulla,
        // e ai livelli di zoom piu' spinti lo sfondo non ha dettagli
        $minimo = 170 / max($this->cosLat, 0.01);
        if ($maxx - $minx < $minimo) {
            $extra = ($minimo - ($maxx - $minx)) / 2;
            $minx -= $extra;
            $maxx += $extra;
        }
        if ($maxy - $miny < $minimo * 0.6) {
            $extra = ($minimo * 0.6 - ($maxy - $miny)) / 2;
            $miny -= $extra;
            $maxy += $extra;
        }

        $larghezza = (int) config('planimetrie.larghezza_px', 1100);
        $altezza = (int) round($larghezza * ($maxy - $miny) / max($maxx - $minx, 0.01));
        $altezza = max(420, min($altezzaMassima, $altezza));
        // L'inquadratura si allarga per rispettare le proporzioni del foglio
        $rapporto = $altezza / $larghezza;
        if (($maxy - $miny) / ($maxx - $minx) > $rapporto) {
            $extra = (($maxy - $miny) / $rapporto - ($maxx - $minx)) / 2;
            $minx -= $extra;
            $maxx += $extra;
        } else {
            $extra = (($maxx - $minx) * $rapporto - ($maxy - $miny)) / 2;
            $miny -= $extra;
            $maxy += $extra;
        }

        $this->bbox = [$minx, $miny, $maxx, $maxy];
        $this->etichetteOccupate = [];
        $this->w = $larghezza * self::SS;
        $this->h = $altezza * self::SS;
        $this->scala = $this->w / ($maxx - $minx);

        $fondo = $this->sfondo->per($this->bbox, $this->w, $this->h);
        $this->conSfondo = $fondo !== null;
        if ($fondo !== null) {
            $this->im = $fondo;
        } else {
            $this->im = imagecreatetruecolor($this->w, $this->h);
            imagefill($this->im, 0, 0, $this->colore(250, 250, 246));
            $this->griglia();
        }
        imagealphablending($this->im, true);
    }

    private function chiudi(): string
    {
        // Riduzione alla misura finale: da qui le curve escono morbide
        $finale = imagecreatetruecolor((int) ($this->w / self::SS), (int) ($this->h / self::SS));
        imagecopyresampled($finale, $this->im, 0, 0, 0, 0,
            (int) ($this->w / self::SS), (int) ($this->h / self::SS), $this->w, $this->h);
        imagedestroy($this->im);

        ob_start();
        imagepng($finale, null, 8);
        imagedestroy($finale);

        return (string) ob_get_clean();
    }

    /** @return array{0: float, 1: float} pixel (grandi) da EPSG:3857 */
    private function px(array $p): array
    {
        return [
            ($p[0] - $this->bbox[0]) * $this->scala,
            ($this->bbox[3] - $p[1]) * $this->scala,
        ];
    }

    /** Metri veri in pixel grandi. */
    private function metri(float $m): float
    {
        return $m / max($this->cosLat, 0.01) * $this->scala;
    }

    private function colore(int $r, int $g, int $b, int $alpha = 0): int
    {
        return (int) imagecolorallocatealpha($this->im, $r, $g, $b, $alpha);
    }

    // --- Fondale tecnico ----------------------------------------------------

    private function griglia(): void
    {
        $larghezzaVera = ($this->bbox[2] - $this->bbox[0]) * $this->cosLat;
        $passo = $this->passoBello($larghezzaVera / 8);
        $passoPx = $this->metri($passo);
        $c = $this->colore(226, 231, 221);
        for ($x = fmod(-$this->bbox[0] * $this->scala, $passoPx); $x < $this->w; $x += $passoPx) {
            imageline($this->im, (int) $x, 0, (int) $x, $this->h, $c);
        }
        for ($y = fmod($this->bbox[3] * $this->scala, $passoPx); $y < $this->h; $y += $passoPx) {
            imageline($this->im, 0, (int) $y, $this->w, (int) $y, $c);
        }
    }

    private function passoBello(float $grezzo): float
    {
        $decade = 10 ** floor(log10(max($grezzo, 0.1)));
        foreach ([1, 2, 5, 10] as $m) {
            if ($m * $decade >= $grezzo) {
                return $m * $decade;
            }
        }

        return 10 * $decade;
    }

    // --- Perimetri e superfici ---------------------------------------------

    /** @param list<list<array{0: float, 1: float}>> $anelli */
    private function disegnaAnelli(array $anelli, bool $fill): void
    {
        foreach ($anelli as $anello) {
            $piatti = [];
            foreach ($anello as $p) {
                [$x, $y] = $this->px($p);
                $piatti[] = $x;
                $piatti[] = $y;
            }
            if (count($piatti) < 6) {
                continue;
            }
            if ($fill) {
                imagefilledpolygon($this->im, array_map(intval(...), $piatti),
                    $this->colore(56, 122, 68, $this->conSfondo ? 100 : 96));
            }
            if ($this->conSfondo) {
                $this->polilinea($anello, $this->colore(255, 255, 255, 28), 12, true);
            }
            $this->polilinea($anello, $this->colore(27, 84, 40), 6, true);
        }
    }

    /** @param list<array{0: float, 1: float}> $anello */
    private function tratteggio(array $anello): void
    {
        $c = $this->colore(90, 90, 90);
        imagesetthickness($this->im, 3);
        $prev = null;
        foreach ($anello as $p) {
            $q = $this->px($p);
            if ($prev !== null) {
                $this->lineaTratteggiata($prev, $q, $c, 14, 10);
            }
            $prev = $q;
        }
        imagesetthickness($this->im, 1);
    }

    private function lineaTratteggiata(array $a, array $b, int $c, float $pieno, float $vuoto): void
    {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $lung = hypot($dx, $dy);
        if ($lung < 0.5) {
            return;
        }
        $passi = $pieno + $vuoto;
        for ($t = 0; $t < $lung; $t += $passi) {
            $fine = min($t + $pieno, $lung);
            imageline($this->im,
                (int) ($a[0] + $dx * $t / $lung), (int) ($a[1] + $dy * $t / $lung),
                (int) ($a[0] + $dx * $fine / $lung), (int) ($a[1] + $dy * $fine / $lung), $c);
        }
    }

    /** Polilinea spessa con giunti tondi (GD da sola li lascia squadrati). */
    private function polilinea(array $punti, int $colore, int $spessore, bool $chiudi = false): void
    {
        imagesetthickness($this->im, $spessore);
        $pixel = array_map(fn ($p) => $this->px($p), $punti);
        if ($chiudi && $pixel !== []) {
            $pixel[] = $pixel[0];
        }
        $prev = null;
        foreach ($pixel as $p) {
            if ($prev !== null) {
                imageline($this->im, (int) $prev[0], (int) $prev[1], (int) $p[0], (int) $p[1], $colore);
            }
            if ($spessore > 2) {
                imagefilledellipse($this->im, (int) $p[0], (int) $p[1], $spessore, $spessore, $colore);
            }
            $prev = $p;
        }
        imagesetthickness($this->im, 1);
    }

    // --- Elementi censiti ---------------------------------------------------

    private function disegnaElemento(array $e): void
    {
        $geo = $e['geo'];
        switch ($e['tipo']) {
            case 'POINT':
            case 'MULTIPOINT':
                // Un MultiPoint (dagli import) disegna ogni sua parte
                $parti = $e['tipo'] === 'POINT' ? [$geo['coordinates']] : $geo['coordinates'];
                foreach ($parti as $i => $parte) {
                    if ($e['albero']) {
                        $this->chioma($parte, $e['chioma_m'], $e['id'].'#'.$i);
                    } else {
                        [$x, $y] = $this->px($parte);
                        imagefilledellipse($this->im, (int) $x, (int) $y, 14, 14, $this->colore(255, 255, 255));
                        imagefilledellipse($this->im, (int) $x, (int) $y, 10, 10, $this->colore(87, 97, 92));
                    }
                }
                break;
            case 'LINESTRING':
            case 'MULTILINESTRING':
                $linee = $e['tipo'] === 'LINESTRING' ? [$geo['coordinates']] : $geo['coordinates'];
                foreach ($linee as $linea) {
                    if ($this->conSfondo) {
                        $this->polilinea($linea, $this->colore(255, 255, 255, 36), 11);
                    }
                    $this->polilinea($linea, $this->colore(74, 118, 27), 6);
                }
                break;
            case 'POLYGON':
            case 'MULTIPOLYGON':
                foreach (\App\Services\Territorio\PlanimetriaDati::anelli($geo) as $anello) {
                    $piatti = [];
                    foreach ($anello as $p) {
                        [$x, $y] = $this->px($p);
                        $piatti[] = (int) $x;
                        $piatti[] = (int) $y;
                    }
                    if (count($piatti) >= 6) {
                        imagefilledpolygon($this->im, $piatti, $this->colore(124, 176, 108, $this->conSfondo ? 84 : 74));
                        $this->polilinea($anello, $this->colore(95, 138, 78), 4, true);
                    }
                }
                break;
        }
    }

    /**
     * La chioma sagomata: contorno lobato deterministico dal codice
     * dell'elemento, velatura di luce a nord-ovest, punto del fusto.
     */
    private function chioma(array $centro3857, ?float $diametroM, string $seme): void
    {
        [$cx, $cy] = $this->px($centro3857);
        $misurata = $diametroM !== null && $diametroM > 0;
        $r = $this->metri(($misurata ? $diametroM : self::CHIOMA_CONVENZIONALE_M) / 2);
        $r = max($r, 7.0);

        $caso = crc32($seme);

        $tenue = ! $misurata;
        // Corpo della chioma
        imagefilledpolygon($this->im, $this->contornoChioma($cx, $cy, $r, $caso, 1.0, 0.0),
            $this->colore(44, 112, 58, $tenue ? 96 : ($this->conSfondo ? 58 : 52)));
        // Velatura di luce verso nord-ovest: toglie l'effetto "bollo piatto"
        imagefilledpolygon($this->im, $this->contornoChioma($cx, $cy, $r, $caso, 0.58, 0.16),
            $this->colore(158, 210, 134, $tenue ? 100 : 62));
        // Contorno
        $bordo = $this->contornoChioma($cx, $cy, $r, $caso, 1.0, 0.0);
        $anello = [];
        for ($i = 0; $i < count($bordo); $i += 2) {
            $anello[] = [$bordo[$i], $bordo[$i + 1]];
        }
        imagesetthickness($this->im, $tenue ? 1 : 3);
        $c = $this->colore(24, 80, 40, $tenue ? 60 : 10);
        $prev = end($anello);
        foreach ($anello as $p) {
            imageline($this->im, (int) $prev[0], (int) $prev[1], (int) $p[0], (int) $p[1], $c);
            $prev = $p;
        }
        imagesetthickness($this->im, 1);
        // Il fusto
        imagefilledellipse($this->im, (int) $cx, (int) $cy, 10, 10, $this->colore(255, 255, 255));
        imagefilledellipse($this->im, (int) $cx, (int) $cy, 7, 7, $this->colore(61, 43, 31));
    }

    /**
     * Il contorno lobato della chioma: sempre lo stesso per lo stesso albero
     * (il caso viene dal suo codice), diverso fra alberi vicini.
     *
     * @return list<int> coordinate piatte x,y per imagefilledpolygon
     */
    private function contornoChioma(float $cx, float $cy, float $r, int $caso, float $scala, float $sposta): array
    {
        $lobi = 5 + $caso % 3;                       // 5..7 gobbe principali
        $fase1 = ($caso % 628) / 100;
        $fase2 = (($caso >> 8) % 628) / 100;
        $fase3 = (($caso >> 16) % 628) / 100;

        $punti = [];
        for ($i = 0; $i < 40; $i++) {
            $t = $i / 40 * 2 * M_PI;
            $raggio = $r * $scala * (1
                + 0.11 * sin($lobi * $t + $fase1)
                + 0.05 * sin(($lobi + 4) * $t + $fase2)
                + 0.028 * sin(($lobi + 8) * $t + $fase3));
            $punti[] = (int) ($cx - $sposta * $r + $raggio * cos($t));
            $punti[] = (int) ($cy - $sposta * $r + $raggio * sin($t));
        }

        return $punti;
    }

    /**
     * L'etichetta dell'elemento, ancorata al punto DENTRO la figura
     * (ST_PointOnSurface: regge anche superfici concave e linee) e senza
     * sovrapporsi alle etichette gia' scritte: se sotto non c'e' posto si
     * prova sopra, e se e' pieno anche li' si rinuncia — un codice
     * illeggibile perche' accavallato e' peggio di un codice in meno.
     */
    private function etichetta(array $e): void
    {
        if (! $e['etichetta']) {
            return;
        }
        [$x, $y] = $this->px($e['ancora']);
        $scarto = in_array($e['tipo'], ['POINT', 'MULTIPOINT'], true)
            ? $this->metri(max(($e['chioma_m'] ?? 0) / 2, 1)) + 16
            : 8;

        $testo = (string) $e['etichetta'];
        $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        $box = imagettfbbox(19, 0, $font, $testo);
        $w = $box[2] - $box[0];

        foreach ([$y + $scarto + 20, $y - $scarto - 8] as $base) {
            $rett = [$x - $w / 2 - 6, $base - 24, $x + $w / 2 + 6, $base + 6];
            $libero = true;
            foreach ($this->etichetteOccupate as $o) {
                if ($rett[0] < $o[2] && $rett[2] > $o[0] && $rett[1] < $o[3] && $rett[3] > $o[1]) {
                    $libero = false;
                    break;
                }
            }
            if ($libero) {
                $this->etichetteOccupate[] = $rett;
                $this->testo($testo, (float) $x, (float) $base, 19, centrato: true);

                return;
            }
        }
    }

    /** Testo con alone bianco: leggibile anche sopra la fotografia. */
    private function testo(string $testo, float $x, float $y, int $corpo, bool $centrato = false, bool $grassetto = false): void
    {
        $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans'.($grassetto ? '-Bold' : '').'.ttf');
        if ($centrato) {
            $box = imagettfbbox($corpo, 0, $font, $testo);
            $x -= ($box[2] - $box[0]) / 2;
        }
        $alone = $this->colore(255, 255, 255, 25);
        foreach ([[-2, 0], [2, 0], [0, -2], [0, 2], [-2, -2], [2, 2], [-2, 2], [2, -2]] as [$dx, $dy]) {
            imagettftext($this->im, $corpo, 0, (int) ($x + $dx), (int) ($y + $dy), $alone, $font, $testo);
        }
        imagettftext($this->im, $corpo, 0, (int) $x, (int) $y, $this->colore(30, 41, 34), $font, $testo);
    }

    private function numeroArea(array $centro3857, string $numero): void
    {
        [$x, $y] = $this->px($centro3857);
        imagefilledellipse($this->im, (int) $x, (int) $y, 64, 64, $this->colore(255, 255, 255, 30));
        imagefilledellipse($this->im, (int) $x, (int) $y, 56, 56, $this->colore(38, 92, 49));
        $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
        $box = imagettfbbox(26, 0, $font, $numero);
        imagettftext($this->im, 26, 0, (int) ($x - ($box[2] - $box[0]) / 2), (int) ($y + 13),
            $this->colore(255, 255, 255), $font, $numero);
    }

    // --- Cartiglio: barra della scala e nord --------------------------------

    private function cartiglio(): void
    {
        // Barra della scala, in metri veri, con lunghezza "bella", dentro
        // un riquadro bianco pieno e bordato come nei cartigli veri
        $larghezzaVera = ($this->bbox[2] - $this->bbox[0]) * $this->cosLat;
        $lunghezza = $this->passoBello($larghezzaVera / 5);
        $lunghezzaPx = $this->metri($lunghezza);

        $nero = $this->colore(25, 30, 26);
        $bordo = $this->colore(150, 155, 148);

        $x0 = 36;
        $y0 = $this->h - 40;
        imagefilledrectangle($this->im, $x0 - 18, $y0 - 46, (int) ($x0 + $lunghezzaPx + 88), $y0 + 22,
            $this->colore(255, 255, 255, 14));
        imagerectangle($this->im, $x0 - 18, $y0 - 46, (int) ($x0 + $lunghezzaPx + 88), $y0 + 22, $bordo);
        // Due tratti alternati
        imagerectangle($this->im, $x0, $y0 - 8, (int) ($x0 + $lunghezzaPx), $y0 + 5, $nero);
        imagefilledrectangle($this->im, $x0, $y0 - 8, (int) ($x0 + $lunghezzaPx / 2), $y0 + 5, $nero);
        $et = fn (float $v) => rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');
        $this->testo('0', $x0, $y0 - 18, 17, centrato: true);
        $this->testo($et($lunghezza / 2), $x0 + $lunghezzaPx / 2, $y0 - 18, 17, centrato: true);
        $this->testo($et($lunghezza).' m', $x0 + $lunghezzaPx + 12, $y0 + 3, 17);

        // Il nord, in alto a destra, in un tondo bianco bordato
        $x = $this->w - 58;
        $y = 60;
        imagefilledellipse($this->im, $x, $y, 84, 84, $this->colore(255, 255, 255, 14));
        imageellipse($this->im, $x, $y, 84, 84, $bordo);
        imagesetthickness($this->im, 3);
        imageline($this->im, $x, $y + 20, $x, $y - 12, $nero);
        imagesetthickness($this->im, 1);
        imagefilledpolygon($this->im, [$x, $y - 28, $x - 9, $y - 8, $x + 9, $y - 8], $nero);
        $this->testo('N', $x, $y + 36, 18, centrato: true, grassetto: true);
    }
}
