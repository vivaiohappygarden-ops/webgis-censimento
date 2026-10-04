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

    /** Larghezza massima dell'inquadratura della mappa accettata dal browser, in pixel. */
    public const LARGHEZZA_SFONDO = 1600;

    /** Oltre questo numero di vicini le etichette si omettono: si coprirebbero a vicenda. */
    public const MASSIMO_ETICHETTE = 120;

    private const CARATTERE = 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf';

    private const CARATTERE_GRASSETTO = 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';

    /** @var array<string, int> */
    private array $colori = [];

    /**
     * @param  array<string, mixed>|null  $sfondo  l'inquadratura della mappa a video mandata dal browser
     *                                             (immagine, confini geografici, attribuzione): con lo sfondo
     *                                             la planimetria si disegna sopra le strade, senza resta il
     *                                             disegno dei soli dati censiti su fondo bianco
     * @return array{png: string, larghezza: int, altezza: int, vicini: int, etichette: bool, aree: int, metri_larghezza: float, metri_altezza: float, srid: int, sfondo: bool, attribuzione: ?string}|null  null senza geometria o senza GD
     */
    public function per(Asset $asset, int $srid, ?array $sfondo = null): ?array
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }
        $inquadratura = $sfondo ? $this->inquadratura($sfondo) : null;

        if ($inquadratura) {
            // Con lo sfondo si lavora in coordinate geografiche: la proiezione e' quella
            // della mappa a video (Mercatore sferica), l'inquadratura e' la finestra
            $riga = DB::table('assets')->where('id', $asset->id)->whereNotNull('geom')
                ->selectRaw('ST_AsGeoJSON(geom)::text AS g, ST_X(ST_Centroid(geom)) AS cx, ST_Y(ST_Centroid(geom)) AS cy')->first();
            if ($riga && $riga->g && ($riga->cx < $inquadratura['west'] || $riga->cx > $inquadratura['east'] || $riga->cy < $inquadratura['south'] || $riga->cy > $inquadratura['north'])) {
                // L'elemento non e' nell'inquadratura: meglio il disegno su fondo bianco che una mappa di un altro posto
                imagedestroy($inquadratura['img']);
                $inquadratura = null;
            }
        }
        if (! $inquadratura) {
            $riga = DB::table('assets')->where('id', $asset->id)->whereNotNull('geom')
                ->selectRaw(
                    'ST_AsGeoJSON(ST_Transform(geom, ?::int))::text AS g, ST_XMin(ST_Transform(geom, ?::int)) AS x1, ST_YMin(ST_Transform(geom, ?::int)) AS y1, ST_XMax(ST_Transform(geom, ?::int)) AS x2, ST_YMax(ST_Transform(geom, ?::int)) AS y2',
                    array_fill(0, 5, $srid)
                )->first();
        }
        if (! $riga || ! $riga->g) {
            if ($inquadratura) {
                imagedestroy($inquadratura['img']);
            }

            return null;
        }
        $geometria = json_decode($riga->g, true);
        if (! is_array($geometria) || empty($geometria['type'])) {
            if ($inquadratura) {
                imagedestroy($inquadratura['img']);
            }

            return null;
        }

        if ($inquadratura) {
            $larghezza = $inquadratura['w'];
            $altezza = $inquadratura['h'];
            $west = $this->mercX($inquadratura['west']);
            $east = $this->mercX($inquadratura['east']);
            $south = $this->mercY($inquadratura['south']);
            $north = $this->mercY($inquadratura['north']);
            $cosLat = cos(deg2rad(($inquadratura['south'] + $inquadratura['north']) / 2));
            $proietta = fn (float $lon, float $lat): array => [
                (int) round(($this->mercX($lon) - $west) / ($east - $west) * $larghezza),
                (int) round(($north - $this->mercY($lat)) / ($north - $south) * $altezza),
            ];
            $pxPerMetro = $larghezza / (($east - $west) * $cosLat);
            $metriLarghezza = ($east - $west) * $cosLat;
            $metriAltezza = ($north - $south) * $cosLat;
            $involucro = sprintf('ST_MakeEnvelope(%F, %F, %F, %F, 4326)', $inquadratura['west'], $inquadratura['south'], $inquadratura['east'], $inquadratura['north']);
            $geomSql = 'ST_AsGeoJSON(%s)::text AS g';
            $legami = [];
        } else {
            $larghezza = self::LARGHEZZA;
            $altezza = self::ALTEZZA;
            $f = $this->finestra((float) $riga->x1, (float) $riga->y1, (float) $riga->x2, (float) $riga->y2);
            $proietta = fn (float $x, float $y): array => [
                (int) round(($x - $f['x1']) / $f['lx'] * $larghezza),
                (int) round(($f['y2'] - $y) / $f['ly'] * $altezza),
            ];
            $pxPerMetro = $larghezza / $f['lx'];
            $metriLarghezza = $f['lx'];
            $metriAltezza = $f['ly'];
            $involucro = sprintf('ST_Transform(ST_MakeEnvelope(%F, %F, %F, %F, %d), 4326)', $f['x1'], $f['y1'], $f['x2'], $f['y2'], $srid);
            $geomSql = 'ST_AsGeoJSON(ST_Transform(%s, ?::int))::text AS g';
            $legami = [$srid];
        }

        // Vicini e aree della stessa organizzazione (lo scope dei modelli), dentro la finestra
        $vicini = Asset::query()->where('assets.id', '<>', $asset->id)
            ->whereRaw("ST_Intersects(assets.geom, {$involucro})")
            ->selectRaw('assets.id, assets.census_code, assets.status, '.sprintf($geomSql, 'assets.geom'), $legami)
            ->orderBy('assets.census_code')->limit(self::MASSIMO_VICINI)->get();
        $aree = Area::query()->whereRaw("ST_Intersects(areas.geom, {$involucro})")
            ->selectRaw('areas.id, areas.name, areas.code, '.sprintf($geomSql, 'areas.geom'), $legami)
            ->orderBy('areas.name')->limit(50)->get();

        $img = $inquadratura['img'] ?? imagecreatetruecolor($larghezza, $altezza);
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
            'vicino_testo' => imagecolorallocate($img, 50, 50, 50),
            'alone_testo' => imagecolorallocatealpha($img, 255, 255, 255, 45),
            'elemento' => imagecolorallocate($img, 22, 163, 74),
            'elemento_bordo' => imagecolorallocate($img, 20, 83, 45),
            'elemento_fondo' => imagecolorallocatealpha($img, 22, 163, 74, 85),
            'chioma' => imagecolorallocatealpha($img, 22, 163, 74, 105),
            'nero' => imagecolorallocate($img, 20, 20, 20),
            'bianco' => imagecolorallocate($img, 255, 255, 255),
        ];
        if (! $inquadratura) {
            imagefill($img, 0, 0, $c['sfondo']);
        }

        foreach ($aree as $area) {
            $g = json_decode($area->g, true);
            if (! is_array($g)) {
                continue;
            }
            $this->disegna($img, $g, $proietta, ['riempimento' => $c['area_fondo'], 'bordo' => $c['area_bordo'], 'spessore' => 2, 'tratteggio' => true, 'raggio' => 4]);
            $centro = $this->centro($g, $proietta);
            if ($centro && $centro[0] > 20 && $centro[0] < $larghezza - 20 && $centro[1] > 20 && $centro[1] < $altezza - 20) {
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
                    $this->etichetta($img, $centro[0] + 9, $centro[1] + 4, (string) $v->census_code, 10, $c['vicino_testo'], false, $inquadratura !== null);
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

        $this->scalaGrafica($img, $pxPerMetro, $metriLarghezza, $altezza);
        $this->nord($img, $larghezza);
        imagerectangle($img, 0, 0, $larghezza - 1, $altezza - 1, $c['cornice']);

        ob_start();
        imagepng($img, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($img);
        if ($png === '') {
            return null;
        }

        return [
            'png' => $png,
            'larghezza' => $larghezza,
            'altezza' => $altezza,
            'vicini' => $vicini->count(),
            'etichette' => $etichette,
            'aree' => $aree->count(),
            'metri_larghezza' => round($metriLarghezza, 1),
            'metri_altezza' => round($metriAltezza, 1),
            'srid' => $srid,
            'sfondo' => $inquadratura !== null,
            'attribuzione' => $inquadratura['attribuzione'] ?? null,
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
            imagefilledrectangle($img, $x + $scatolaTesto[6] - 3, $y + $scatolaTesto[7] - 2, $x + $scatolaTesto[2] + 3, $y + $scatolaTesto[3] + 2, $grassetto ? $this->colori['bianco'] : ($this->colori['alone_testo'] ?? $this->colori['bianco']));
        }
        imagettftext($img, $corpo, 0, $x, $y, $colore, $carattere, $testo);
    }

    /** La scala grafica in basso a sinistra: un numero tondo di metri, meta' nera e meta' bianca. */
    private function scalaGrafica(GdImage $img, float $pxPerMetro, float $metriLarghezza, int $altezza): void
    {
        $metri = $this->numeroTondo($metriLarghezza / 4);
        if ($metri <= 0) {
            return;
        }
        $lunghezza = (int) round($metri * $pxPerMetro);
        $x = 24;
        $y = $altezza - 30;
        imagefilledrectangle($img, $x - 10, $y - 24, $x + $lunghezza + 46, $y + 14, $this->colori['bianco']);
        imagerectangle($img, $x - 10, $y - 24, $x + $lunghezza + 46, $y + 14, $this->colori['cornice']);
        imagefilledrectangle($img, $x, $y, $x + intdiv($lunghezza, 2), $y + 7, $this->colori['nero']);
        imagerectangle($img, $x, $y, $x + $lunghezza, $y + 7, $this->colori['nero']);
        $etichetta = $metri >= 1000 ? rtrim(rtrim(number_format($metri / 1000, 2, ',', '.'), '0'), ',').' km' : rtrim(rtrim(number_format($metri, 1, ',', '.'), '0'), ',').' m';
        $this->etichetta($img, $x, $y - 6, '0', 11, $this->colori['nero'], false, false);
        $this->etichetta($img, $x + $lunghezza - 6, $y - 6, $etichetta, 11, $this->colori['nero'], false, false);
    }

    /** La freccia del nord in alto a destra: nel sistema metrico la mappa e' orientata a nord. */
    private function nord(GdImage $img, int $larghezza): void
    {
        $cx = $larghezza - 40;
        $cy = 44;
        imagefilledellipse($img, $cx, $cy, 52, 52, $this->colori['bianco']);
        imageellipse($img, $cx, $cy, 52, 52, $this->colori['cornice']);
        imagefilledpolygon($img, [$cx, $cy - 18, $cx - 9, $cy + 10, $cx, $cy + 4, $cx + 9, $cy + 10], $this->colori['nero']);
        $this->etichetta($img, $cx - 5, $cy + 42, 'N', 12, $this->colori['nero'], true, false);
    }

    /**
     * L'inquadratura mandata dal browser, controllata: immagine leggibile,
     * confini sensati (nord in alto, al piu' qualche chilometro), ridotta a
     * LARGHEZZA_SFONDO. Null se qualcosa non torna: si stampa su fondo bianco.
     *
     * @param  array<string, mixed>  $sfondo
     * @return array{img: GdImage, w: int, h: int, west: float, south: float, east: float, north: float, attribuzione: ?string}|null
     */
    private function inquadratura(array $sfondo): ?array
    {
        $b = $sfondo['bounds'] ?? null;
        if (! is_array($b) || ! is_string($sfondo['immagine'] ?? null)) {
            return null;
        }
        foreach (['west', 'south', 'east', 'north'] as $k) {
            if (! isset($b[$k]) || ! is_numeric($b[$k])) {
                return null;
            }
        }
        [$west, $south, $east, $north] = [(float) $b['west'], (float) $b['south'], (float) $b['east'], (float) $b['north']];
        if ($west >= $east || $south >= $north || $west < -180 || $east > 180 || $south < -85 || $north > 85
            || $east - $west > 0.2 || $north - $south > 0.2 || $east - $west < 0.00002 || $north - $south < 0.00001) {
            return null;
        }
        $byte = (new PartiComuni)->byteDaDataUri($sfondo['immagine']);
        if (! $byte || strlen($byte) > 12_000_000) {
            return null;
        }
        $info = @getimagesizefromstring($byte);
        if (! $info || $info[0] < 100 || $info[1] < 100 || $info[0] > 6000 || $info[1] > 6000) {
            return null;
        }
        $img = @imagecreatefromstring($byte);
        if (! $img) {
            return null;
        }
        if (imagesx($img) > self::LARGHEZZA_SFONDO) {
            $ridotta = imagescale($img, self::LARGHEZZA_SFONDO);
            imagedestroy($img);
            if (! $ridotta) {
                return null;
            }
            $img = $ridotta;
        }
        if (! imageistruecolor($img)) {
            $tela = imagecreatetruecolor(imagesx($img), imagesy($img));
            imagecopy($tela, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
            imagedestroy($img);
            $img = $tela;
        }
        $attribuzione = isset($sfondo['attribuzione']) && is_string($sfondo['attribuzione']) ? trim(mb_substr($sfondo['attribuzione'], 0, 300)) : '';

        return ['img' => $img, 'w' => imagesx($img), 'h' => imagesy($img), 'west' => $west, 'south' => $south, 'east' => $east, 'north' => $north, 'attribuzione' => $attribuzione !== '' ? $attribuzione : null];
    }

    /** Mercatore sferica (EPSG:3857), la proiezione della mappa a video: la x. */
    private function mercX(float $lon): float
    {
        return 6378137.0 * deg2rad($lon);
    }

    /** Mercatore sferica: la y. */
    private function mercY(float $lat): float
    {
        return 6378137.0 * log(tan(M_PI / 4 + deg2rad(max(-85.0, min(85.0, $lat))) / 2));
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
