<?php

namespace App\Services\Pdf;

use App\Models\Area;
use App\Models\Asset;
use App\Support\AssetStatus;
use GdImage;
use Illuminate\Support\Facades\DB;

/**
 * La planimetria schematica di un elemento per la scheda stampata: l'elemento
 * al centro (con la chioma a misura, se censita), gli elementi vicini con il
 * numero del cartellino, i confini delle aree di gestione, scala grafica e
 * nord, nel sistema metrico dell'organizzazione. Senza sfondo cartografico:
 * lo sfondo a video arriva da server esterni che il server non interroga, e
 * un disegno dei soli dati censiti e' comunque una planimetria, non
 * un'illustrazione.
 */
class PlanimetriaElemento
{
    public const LARGHEZZA = 1200;

    public const ALTEZZA = 800;

    /** Lato corto minimo della finestra attorno all'elemento, in metri. */
    public const FINESTRA_MINIMA_M = 60.0;

    public const MASSIMO_VICINI = 300;

    /** Oltre questo numero di vicini le etichette si omettono: si coprirebbero a vicenda. */
    public const MASSIMO_ETICHETTE = 120;

    private const CARATTERE = 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf';

    private const CARATTERE_GRASSETTO = 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';

    /** @var array<string, int> */
    private array $colori = [];

    /**
     * @return array{png: string, larghezza: int, altezza: int, vicini: int, etichette: bool, aree: int, metri_larghezza: float, metri_altezza: float, srid: int}|null  null senza geometria o senza GD
     */
    public function per(Asset $asset, int $srid): ?array
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }
        $riga = DB::table('assets')->where('id', $asset->id)->whereNotNull('geom')
            ->selectRaw(
                'ST_AsGeoJSON(ST_Transform(geom, ?::int))::text AS g, ST_XMin(ST_Transform(geom, ?::int)) AS x1, ST_YMin(ST_Transform(geom, ?::int)) AS y1, ST_XMax(ST_Transform(geom, ?::int)) AS x2, ST_YMax(ST_Transform(geom, ?::int)) AS y2',
                array_fill(0, 5, $srid)
            )->first();
        if (! $riga || ! $riga->g) {
            return null;
        }
        $geometria = json_decode($riga->g, true);
        if (! is_array($geometria) || empty($geometria['type'])) {
            return null;
        }
        $f = $this->finestra((float) $riga->x1, (float) $riga->y1, (float) $riga->x2, (float) $riga->y2);
        $involucro = sprintf('ST_Transform(ST_MakeEnvelope(%F, %F, %F, %F, %d), 4326)', $f['x1'], $f['y1'], $f['x2'], $f['y2'], $srid);

        // Vicini e aree della stessa organizzazione (lo scope dei modelli), dentro la finestra
        $vicini = Asset::query()->where('assets.id', '<>', $asset->id)
            ->whereRaw("ST_Intersects(assets.geom, {$involucro})")
            ->selectRaw('assets.id, assets.census_code, assets.status, ST_AsGeoJSON(ST_Transform(assets.geom, ?::int))::text AS g', [$srid])
            ->orderBy('assets.census_code')->limit(self::MASSIMO_VICINI)->get();
        $aree = Area::query()->whereRaw("ST_Intersects(areas.geom, {$involucro})")
            ->selectRaw('areas.id, areas.name, areas.code, ST_AsGeoJSON(ST_Transform(areas.geom, ?::int))::text AS g', [$srid])
            ->orderBy('areas.name')->limit(50)->get();

        $img = imagecreatetruecolor(self::LARGHEZZA, self::ALTEZZA);
        imagealphablending($img, true);
        $c = $this->colori = [
            'sfondo' => imagecolorallocate($img, 255, 255, 255),
            'cornice' => imagecolorallocate($img, 110, 110, 110),
            'area_fondo' => imagecolorallocatealpha($img, 22, 163, 74, 118),
            'area_bordo' => imagecolorallocate($img, 21, 128, 61),
            'area_testo' => imagecolorallocate($img, 20, 83, 45),
            'vicino' => imagecolorallocate($img, 120, 120, 120),
            'vicino_fondo' => imagecolorallocatealpha($img, 120, 120, 120, 100),
            'vicino_archivio' => imagecolorallocate($img, 190, 190, 190),
            'vicino_testo' => imagecolorallocate($img, 70, 70, 70),
            'elemento' => imagecolorallocate($img, 22, 163, 74),
            'elemento_bordo' => imagecolorallocate($img, 20, 83, 45),
            'elemento_fondo' => imagecolorallocatealpha($img, 22, 163, 74, 85),
            'chioma' => imagecolorallocatealpha($img, 22, 163, 74, 105),
            'nero' => imagecolorallocate($img, 20, 20, 20),
            'bianco' => imagecolorallocate($img, 255, 255, 255),
        ];
        imagefill($img, 0, 0, $c['sfondo']);
        $proietta = fn (float $x, float $y): array => [
            (int) round(($x - $f['x1']) / $f['lx'] * self::LARGHEZZA),
            (int) round(($f['y2'] - $y) / $f['ly'] * self::ALTEZZA),
        ];
        $pxPerMetro = self::LARGHEZZA / $f['lx'];

        foreach ($aree as $area) {
            $g = json_decode($area->g, true);
            if (! is_array($g)) {
                continue;
            }
            $this->disegna($img, $g, $proietta, ['riempimento' => $c['area_fondo'], 'bordo' => $c['area_bordo'], 'spessore' => 2, 'tratteggio' => true, 'raggio' => 4]);
            $centro = $this->centro($g, $proietta);
            if ($centro && $centro[0] > 20 && $centro[0] < self::LARGHEZZA - 20 && $centro[1] > 20 && $centro[1] < self::ALTEZZA - 20) {
                $this->etichetta($img, $centro[0], $centro[1], (string) $area->name, 11, $c['area_testo'], true, true);
            }
        }

        $etichette = $vicini->count() <= self::MASSIMO_ETICHETTE;
        foreach ($vicini as $v) {
            $g = json_decode($v->g, true);
            if (! is_array($g)) {
                continue;
            }
            $archivio = AssetStatus::inArchivio((string) $v->status);
            $colore = $archivio ? $c['vicino_archivio'] : $c['vicino'];
            $this->disegna($img, $g, $proietta, ['riempimento' => $archivio ? null : $c['vicino_fondo'], 'bordo' => $colore, 'spessore' => 3, 'tratteggio' => false, 'raggio' => 6, 'alone' => $c['bianco']]);
            if ($etichette && $v->census_code) {
                $centro = $this->centro($g, $proietta);
                if ($centro) {
                    $this->etichetta($img, $centro[0] + 9, $centro[1] + 4, (string) $v->census_code, 10, $c['vicino_testo'], false, false);
                }
            }
        }

        // L'elemento: la chioma a misura (solo per i punti), poi la geometria, poi il cartellino
        $diametro = (float) ($asset->tree?->crown_diameter_m ?? 0);
        if ($diametro > 0 && $geometria['type'] === 'Point') {
            [$px, $py] = $proietta((float) $geometria['coordinates'][0], (float) $geometria['coordinates'][1]);
            $d = (int) round($diametro * $pxPerMetro);
            imagefilledellipse($img, $px, $py, $d, $d, $c['chioma']);
            imageellipse($img, $px, $py, $d, $d, $c['elemento_bordo']);
        }
        $this->disegna($img, $geometria, $proietta, ['riempimento' => $c['elemento_fondo'], 'bordo' => $c['elemento'], 'spessore' => 5, 'tratteggio' => false, 'raggio' => 9, 'alone' => $c['bianco']]);
        $centro = $this->centro($geometria, $proietta);
        if ($centro && $asset->census_code) {
            $this->etichetta($img, $centro[0] + 12, $centro[1] - 10, (string) $asset->census_code, 13, $c['elemento_bordo'], true, true);
        }

        $this->scalaGrafica($img, $pxPerMetro, $f['lx']);
        $this->nord($img);
        imagerectangle($img, 0, 0, self::LARGHEZZA - 1, self::ALTEZZA - 1, $c['cornice']);

        ob_start();
        imagepng($img, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($img);
        if ($png === '') {
            return null;
        }

        return [
            'png' => $png,
            'larghezza' => self::LARGHEZZA,
            'altezza' => self::ALTEZZA,
            'vicini' => $vicini->count(),
            'etichette' => $etichette,
            'aree' => $aree->count(),
            'metri_larghezza' => round($f['lx'], 1),
            'metri_altezza' => round($f['ly'], 1),
            'srid' => $srid,
        ];
    }

    /**
     * La finestra attorno al riquadro dell'elemento: allargato del 40 per cento,
     * mai sotto FINESTRA_MINIMA_M sul lato corto, con le proporzioni dell'immagine.
     *
     * @return array{x1: float, y1: float, x2: float, y2: float, lx: float, ly: float}
     */
    private function finestra(float $x1, float $y1, float $x2, float $y2): array
    {
        $rapporto = self::LARGHEZZA / self::ALTEZZA;
        $cx = ($x1 + $x2) / 2;
        $cy = ($y1 + $y2) / 2;
        $lx = max(($x2 - $x1) * 1.4, self::FINESTRA_MINIMA_M * $rapporto);
        $ly = max(($y2 - $y1) * 1.4, self::FINESTRA_MINIMA_M);
        if ($lx / $ly > $rapporto) {
            $ly = $lx / $rapporto;
        } else {
            $lx = $ly * $rapporto;
        }

        return ['x1' => $cx - $lx / 2, 'y1' => $cy - $ly / 2, 'x2' => $cx + $lx / 2, 'y2' => $cy + $ly / 2, 'lx' => $lx, 'ly' => $ly];
    }

    /**
     * @param  callable(float, float): array{0: int, 1: int}  $proietta
     * @param  array{riempimento: ?int, bordo: int, spessore: int, tratteggio: bool, raggio: int, alone?: int}  $stile
     */
    private function disegna(GdImage $img, array $g, callable $proietta, array $stile): void
    {
        $coordinate = $g['coordinates'] ?? [];
        $punti = fn (array $anello): array => array_map(fn ($p) => $proietta((float) $p[0], (float) $p[1]), $anello);
        switch ($g['type'] ?? '') {
            case 'Point':
                $this->punto($img, $proietta((float) $coordinate[0], (float) $coordinate[1]), $stile);
                break;
            case 'MultiPoint':
                foreach ($coordinate as $p) {
                    $this->punto($img, $proietta((float) $p[0], (float) $p[1]), $stile);
                }
                break;
            case 'LineString':
                $this->linea($img, $punti($coordinate), $stile);
                break;
            case 'MultiLineString':
                foreach ($coordinate as $l) {
                    $this->linea($img, $punti($l), $stile);
                }
                break;
            case 'Polygon':
                $this->poligono($img, $punti($coordinate[0] ?? []), $stile);
                break;
            case 'MultiPolygon':
                foreach ($coordinate as $pg) {
                    $this->poligono($img, $punti($pg[0] ?? []), $stile);
                }
                break;
            case 'GeometryCollection':
                foreach ($g['geometries'] ?? [] as $gg) {
                    $this->disegna($img, $gg, $proietta, $stile);
                }
                break;
        }
    }

    /** @param  array{0: int, 1: int}  $p */
    private function punto(GdImage $img, array $p, array $stile): void
    {
        $r = (int) $stile['raggio'];
        if (isset($stile['alone'])) {
            imagefilledellipse($img, $p[0], $p[1], 2 * $r + 6, 2 * $r + 6, $stile['alone']);
        }
        imagefilledellipse($img, $p[0], $p[1], 2 * $r, 2 * $r, $stile['bordo']);
    }

    /** @param  list<array{0: int, 1: int}>  $punti */
    private function linea(GdImage $img, array $punti, array $stile): void
    {
        if (count($punti) < 2) {
            return;
        }
        if (isset($stile['alone'])) {
            imagesetthickness($img, (int) $stile['spessore'] + 4);
            for ($i = 1, $n = count($punti); $i < $n; $i++) {
                imageline($img, $punti[$i - 1][0], $punti[$i - 1][1], $punti[$i][0], $punti[$i][1], $stile['alone']);
            }
        }
        imagesetthickness($img, (int) $stile['spessore']);
        $colore = $stile['bordo'];
        if (! empty($stile['tratteggio'])) {
            imagesetstyle($img, [...array_fill(0, 9, $stile['bordo']), ...array_fill(0, 6, IMG_COLOR_TRANSPARENT)]);
            $colore = IMG_COLOR_STYLED;
        }
        for ($i = 1, $n = count($punti); $i < $n; $i++) {
            imageline($img, $punti[$i - 1][0], $punti[$i - 1][1], $punti[$i][0], $punti[$i][1], $colore);
        }
        imagesetthickness($img, 1);
    }

    /** @param  list<array{0: int, 1: int}>  $punti */
    private function poligono(GdImage $img, array $punti, array $stile): void
    {
        if (count($punti) < 3) {
            return;
        }
        if ($stile['riempimento'] !== null) {
            imagefilledpolygon($img, array_merge(...array_map(fn ($p) => [$p[0], $p[1]], $punti)), $stile['riempimento']);
        }
        $chiuso = $punti;
        if ($chiuso[0] !== $chiuso[count($chiuso) - 1]) {
            $chiuso[] = $chiuso[0];
        }
        $this->linea($img, $chiuso, [...$stile, 'alone' => null] + $stile);
    }

    /**
     * Il centro di una geometria in pixel: il punto stesso, o la media dei
     * vertici per linee e poligoni (basta per appoggiarci un'etichetta).
     *
     * @return array{0: int, 1: int}|null
     */
    private function centro(array $g, callable $proietta): ?array
    {
        $vertici = [];
        $raccogli = function ($c) use (&$raccogli, &$vertici): void {
            if (! is_array($c) || $c === []) {
                return;
            }
            if (is_numeric($c[0] ?? null)) {
                $vertici[] = [(float) $c[0], (float) $c[1]];

                return;
            }
            foreach ($c as $cc) {
                $raccogli($cc);
            }
        };
        if (($g['type'] ?? '') === 'GeometryCollection') {
            foreach ($g['geometries'] ?? [] as $gg) {
                $raccogli($gg['coordinates'] ?? []);
            }
        } else {
            $raccogli($g['coordinates'] ?? []);
        }
        if (! $vertici) {
            return null;
        }
        $x = array_sum(array_column($vertici, 0)) / count($vertici);
        $y = array_sum(array_column($vertici, 1)) / count($vertici);

        return $proietta($x, $y);
    }

    /** Un testo con, se richiesto, una scatola bianca sotto perche' resti leggibile sopra i disegni. */
    private function etichetta(GdImage $img, int $x, int $y, string $testo, float $corpo, int $colore, bool $grassetto, bool $scatola): void
    {
        $carattere = base_path($grassetto ? self::CARATTERE_GRASSETTO : self::CARATTERE);
        if (! is_file($carattere)) {
            imagestring($img, $grassetto ? 4 : 3, $x, $y - 10, $testo, $colore);

            return;
        }
        $scatolaTesto = imagettfbbox($corpo, 0, $carattere, $testo);
        if ($scatola && $scatolaTesto) {
            imagefilledrectangle($img, $x + $scatolaTesto[6] - 3, $y + $scatolaTesto[7] - 2, $x + $scatolaTesto[2] + 3, $y + $scatolaTesto[3] + 2, $this->colori['bianco']);
        }
        imagettftext($img, $corpo, 0, $x, $y, $colore, $carattere, $testo);
    }

    /** La scala grafica in basso a sinistra: un numero tondo di metri, meta' nera e meta' bianca. */
    private function scalaGrafica(GdImage $img, float $pxPerMetro, float $metriLarghezza): void
    {
        $metri = $this->numeroTondo($metriLarghezza / 4);
        if ($metri <= 0) {
            return;
        }
        $lunghezza = (int) round($metri * $pxPerMetro);
        $x = 24;
        $y = self::ALTEZZA - 30;
        imagefilledrectangle($img, $x - 10, $y - 24, $x + $lunghezza + 46, $y + 14, $this->colori['bianco']);
        imagerectangle($img, $x - 10, $y - 24, $x + $lunghezza + 46, $y + 14, $this->colori['cornice']);
        imagefilledrectangle($img, $x, $y, $x + intdiv($lunghezza, 2), $y + 7, $this->colori['nero']);
        imagerectangle($img, $x, $y, $x + $lunghezza, $y + 7, $this->colori['nero']);
        $etichetta = $metri >= 1000 ? rtrim(rtrim(number_format($metri / 1000, 2, ',', '.'), '0'), ',').' km' : rtrim(rtrim(number_format($metri, 1, ',', '.'), '0'), ',').' m';
        $this->etichetta($img, $x, $y - 6, '0', 11, $this->colori['nero'], false, false);
        $this->etichetta($img, $x + $lunghezza - 6, $y - 6, $etichetta, 11, $this->colori['nero'], false, false);
    }

    /** La freccia del nord in alto a destra: nel sistema metrico la mappa e' orientata a nord. */
    private function nord(GdImage $img): void
    {
        $cx = self::LARGHEZZA - 40;
        $cy = 44;
        imagefilledellipse($img, $cx, $cy, 52, 52, $this->colori['bianco']);
        imageellipse($img, $cx, $cy, 52, 52, $this->colori['cornice']);
        imagefilledpolygon($img, [$cx, $cy - 18, $cx - 9, $cy + 10, $cx, $cy + 4, $cx + 9, $cy + 10], $this->colori['nero']);
        $this->etichetta($img, $cx - 5, $cy + 42, 'N', 12, $this->colori['nero'], true, false);
    }

    private function numeroTondo(float $massimo): float
    {
        if ($massimo <= 0) {
            return 0.0;
        }
        $potenza = 10 ** floor(log10($massimo));
        foreach ([5, 2, 1] as $m) {
            if ($m * $potenza <= $massimo) {
                return $m * $potenza;
            }
        }

        return $potenza;
    }
}
