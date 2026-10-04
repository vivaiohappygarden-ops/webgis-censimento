<?php

namespace App\Services\Export;

use App\Models\Organization;
use App\Services\Pdf\Intestazione;
use App\Services\Pdf\PartiComuni;
use App\Services\Pdf\ScrittorePdf;
use Carbon\CarbonInterface;

/**
 * La stampa della mappa in PDF (punto 1 dell'elenco del committente del
 * 04/10/2026): l'immagine che il browser ha disegnato (lo sfondo arriva da
 * server esterni che il server non interroga), con l'intestazione
 * dell'organizzazione, titolo, data, scala a video e scala grafica, freccia
 * del nord, coordinate del centro, legenda e attribuzione dello sfondo. Il
 * foglio segue l'immagine: orizzontale se e' piu' larga che alta.
 */
class MappaPdf
{
    public const TITOLO = 'Mappa del verde';

    /** Le forme dei simboli di legenda che il browser puo' chiedere. */
    public const FORME = ['punto', 'linea', 'tratteggio', 'area', 'anello', 'segnalazione'];

    /** Lato massimo dell'immagine accettata, in pixel. */
    public const LATO_MASSIMO = 6000;

    private const MARGINE = 28.0;

    public function __construct(private PartiComuni $parti = new PartiComuni) {}

    /**
     * L'immagine mandata dal browser (data URI o base64) come JPEG pronto per
     * il PDF; null se non e' un'immagine leggibile o e' fuori misura.
     *
     * @return array{jpeg: string, larghezza: int, altezza: int}|null
     */
    public function immagineDa(string $dati): ?array
    {
        $byte = $this->parti->byteDaDataUri($dati);
        if (! $byte) {
            return null;
        }
        $info = @getimagesizefromstring($byte);
        if (! $info || $info[0] > self::LATO_MASSIMO || $info[1] > self::LATO_MASSIMO) {
            return null;
        }

        return $this->parti->jpegDa($byte, 88);
    }

    /**
     * @param  array{organization: ?Organization, immagine: array{jpeg: string, larghezza: int, altezza: int}, titolo: ?string, sottotitolo: ?string, scala: ?string, metri_larghezza: ?float, rotazione: ?float, centro: array<string, ?string>, legenda: list<array{etichetta: string, colore: string, forma: string}>, attribuzione: ?string, stampatoIl: CarbonInterface}  $dati
     */
    public function componi(array $dati): string
    {
        $img = $dati['immagine'];
        $orizzontale = $img['larghezza'] >= $img['altezza'];
        $pdf = $orizzontale ? new ScrittorePdf : new ScrittorePdf(ScrittorePdf::A4_VERTICALE[0], ScrittorePdf::A4_VERTICALE[1]);
        $organizzazione = $dati['organization'];
        $titolo = trim((string) ($dati['titolo'] ?? '')) !== '' ? trim($dati['titolo']) : self::TITOLO;
        $pdf->intitola($titolo, $organizzazione?->name ?? '');
        $pdf->nuovaPagina();
        $x = self::MARGINE;
        $larghezza = $pdf->larghezza - 2 * self::MARGINE;
        $y = $this->parti->intestazione($pdf, Intestazione::per($organizzazione?->id), $x, 24.0, $larghezza);

        $pdf->testo($x, $y + 11, $titolo, 13, 'grassetto');
        $y += 17;
        $stampato = $dati['stampatoIl']->format('d/m/Y H:i');
        $centro = $dati['centro'] ?? [];
        $meta = array_filter([
            trim((string) ($dati['sottotitolo'] ?? '')) ?: null,
            'stampata il '.$stampato,
            ! empty($dati['scala']) ? 'scala a video circa '.$dati['scala'] : null,
            ! empty($centro['wgs84']) ? 'centro '.$centro['wgs84'] : null,
            ! empty($centro['metrico']) ? $centro['metrico'].(! empty($centro['sistema']) ? ' ('.$centro['sistema'].')' : '') : null,
        ]);
        foreach ($pdf->spezza(implode(' - ', $meta), $larghezza, 7.5) as $r) {
            $pdf->testo($x, $y + 7, $r, 7.5, 'normale', PartiComuni::GRIGIO);
            $y += 10;
        }
        $y += 4;

        // L'immagine prende lo spazio che resta sopra legenda e note
        $colonne = $orizzontale ? 4 : 3;
        $legenda = $this->misuraLegenda($pdf, array_values($dati['legenda'] ?? []), $larghezza, $colonne);
        $altezzaLegenda = $legenda['altezza'];
        $altezzaNote = 26;
        $disponibile = max(80.0, $pdf->altezza - 34 - $y - $altezzaLegenda - $altezzaNote);
        $scala = min($larghezza / $img['larghezza'], $disponibile / $img['altezza']);
        $l = $img['larghezza'] * $scala;
        $a = $img['altezza'] * $scala;
        $xi = $x + ($larghezza - $l) / 2;
        $pdf->immagineJpeg($img['jpeg'], $xi, $y, $l, $a);
        $pdf->rettangolo($xi, $y, $l, $a, null, [0.45, 0.45, 0.45], 0.6);
        $this->scalaGrafica($pdf, $xi, $y + $a, $l, isset($dati['metri_larghezza']) ? (float) $dati['metri_larghezza'] : null);
        $this->nord($pdf, $xi + $l - 18, $y + 18, (float) ($dati['rotazione'] ?? 0));
        $y += $a + 8;

        $y = $this->legenda($pdf, $legenda, $x, $y, $larghezza, $colonne);

        $note = array_filter([
            trim((string) ($dati['attribuzione'] ?? '')) ?: null,
            'La scala vale per la finestra da cui è stata stampata la mappa: per misure fedeli si usano le coordinate e le misure del programma.',
        ]);
        foreach ($pdf->spezza(implode(' - ', $note), $larghezza, 6.5) as $r) {
            $pdf->testo($x, $y + 7, $r, 6.5, 'normale', PartiComuni::GRIGIO);
            $y += 8.5;
        }

        $this->parti->piede($pdf, ($organizzazione?->name ? $titolo.' - '.$organizzazione->name : $titolo).' - stampata il '.$stampato, self::MARGINE);

        return $pdf->salva();
    }

    /** La scala grafica in basso a sinistra: una barra bianca e nera lunga un numero tondo di metri. */
    private function scalaGrafica(ScrittorePdf $pdf, float $x, float $fondo, float $larghezzaImmagine, ?float $metriLarghezza): void
    {
        if (! $metriLarghezza || $metriLarghezza <= 0) {
            return;
        }
        $metri = $this->numeroTondo($metriLarghezza / 4);
        if ($metri <= 0) {
            return;
        }
        $lunghezza = $metri / $metriLarghezza * $larghezzaImmagine;
        $etichetta = $metri >= 1000
            ? rtrim(rtrim(number_format($metri / 1000, 2, ',', '.'), '0'), ',').' km'
            : rtrim(rtrim(number_format($metri, 1, ',', '.'), '0'), ',').' m';
        $xb = $x + 10;
        $yb = $fondo - 14;
        $pdf->rettangolo($xb - 5, $yb - 11, $lunghezza + 10 + $pdf->larghezzaTesto($etichetta, 6.5) / 2 + 2, 20, [1, 1, 1], [0.6, 0.6, 0.6], 0.3);
        $pdf->rettangolo($xb, $yb, $lunghezza / 2, 4, [0.1, 0.1, 0.1], [0.1, 0.1, 0.1], 0.4);
        $pdf->rettangolo($xb + $lunghezza / 2, $yb, $lunghezza / 2, 4, [1, 1, 1], [0.1, 0.1, 0.1], 0.4);
        $pdf->testo($xb, $yb - 2.5, '0', 6.5);
        $pdf->testo($xb + $lunghezza - $pdf->larghezzaTesto($etichetta, 6.5) / 2, $yb - 2.5, $etichetta, 6.5);
    }

    /** La freccia del nord in alto a destra, ruotata di quanto era ruotata la mappa a video. */
    private function nord(ScrittorePdf $pdf, float $cx, float $cy, float $rotazione): void
    {
        $pdf->cerchio($cx, $cy, 11, [1, 1, 1], [0.45, 0.45, 0.45], 0.5);
        // In MapLibre la rotazione (bearing) e' in senso antiorario dal nord:
        // la freccia che a video punta in alto va ruotata in senso antiorario
        $t = deg2rad($rotazione);
        $ruota = fn (float $dx, float $dy): array => [$cx + $dx * cos($t) + $dy * sin($t), $cy - $dx * sin($t) + $dy * cos($t)];
        $pdf->poligono([$ruota(0, -8.5), $ruota(-4.5, 5), $ruota(0, 2.5), $ruota(4.5, 5)], [0.1, 0.1, 0.1]);
        $pdf->testo($cx - $pdf->larghezzaTesto('N', 6, 'grassetto') / 2, $cy + 19, 'N', 6, 'grassetto');
    }

    /**
     * Le voci della legenda spezzate su al piu' due righe e l'altezza che
     * occupano: si misura prima di disegnare l'immagine, per riservare lo spazio.
     *
     * @param  list<array{etichetta: string, colore: string, forma: string}>  $voci
     * @return array{voci: list<array{etichetta: string, colore: string, forma: string, righe: list<string>}>, altezze: list<float>, altezza: float}
     */
    private function misuraLegenda(ScrittorePdf $pdf, array $voci, float $larghezza, int $colonne): array
    {
        if (! $voci) {
            return ['voci' => [], 'altezze' => [], 'altezza' => 0.0];
        }
        $lc = $larghezza / $colonne;
        $altezze = [];
        foreach ($voci as $i => $voce) {
            $righe = array_slice($pdf->spezza((string) $voce['etichetta'], $lc - 24, 7), 0, 2);
            $voci[$i]['righe'] = $righe ?: [''];
            $r = intdiv($i, $colonne);
            $altezze[$r] = max($altezze[$r] ?? 0.0, count($voci[$i]['righe']) * 8.5 + 3.5);
        }

        return ['voci' => $voci, 'altezze' => array_values($altezze), 'altezza' => array_sum($altezze) + 15];
    }

    /** @param  array{voci: list<array<string, mixed>>, altezze: list<float>, altezza: float}  $legenda */
    private function legenda(ScrittorePdf $pdf, array $legenda, float $x, float $y, float $larghezza, int $colonne): float
    {
        if (! $legenda['voci']) {
            return $y;
        }
        $pdf->testo($x, $y + 7, 'Legenda', 7.5, 'grassetto');
        $y += 11;
        $lc = $larghezza / $colonne;
        $inizioRiga = $y;
        foreach ($legenda['voci'] as $i => $voce) {
            $r = intdiv($i, $colonne);
            if ($i > 0 && $i % $colonne === 0) {
                $inizioRiga += $legenda['altezze'][$r - 1];
            }
            $cx = $x + ($i % $colonne) * $lc;
            $this->simbolo($pdf, $voce, $cx, $inizioRiga + 1.5);
            foreach ($voce['righe'] as $k => $riga) {
                $pdf->testo($cx + 19, $inizioRiga + 8 + $k * 8.5, $riga, 7);
            }
        }

        return $y + array_sum($legenda['altezze']) + 4;
    }

    private function simbolo(ScrittorePdf $pdf, array $voce, float $x, float $y): void
    {
        $c = $this->rgb((string) $voce['colore']);
        switch ($voce['forma'] ?? 'punto') {
            case 'linea':
                $pdf->linea($x, $y + 4, $x + 14, $y + 4, 2.2, $c);
                break;
            case 'tratteggio':
                $pdf->linea($x, $y + 4, $x + 14, $y + 4, 2.2, $c, [3, 2]);
                break;
            case 'area':
                $pdf->rettangolo($x, $y, 14, 8, [0.93, 0.97, 0.93]);
                foreach ([[$x, $y, $x + 14, $y], [$x + 14, $y, $x + 14, $y + 8], [$x + 14, $y + 8, $x, $y + 8], [$x, $y + 8, $x, $y]] as [$x1, $y1, $x2, $y2]) {
                    $pdf->linea($x1, $y1, $x2, $y2, 0.9, $c, [2, 1.5]);
                }
                break;
            case 'anello':
                $pdf->cerchio($x + 7, $y + 4, 3.6, null, $c, 2);
                break;
            case 'segnalazione':
                $pdf->cerchio($x + 7, $y + 4, 3.8, $c, [0.5, 0.11, 0.11], 1);
                break;
            default:
                $pdf->cerchio($x + 7, $y + 4, 3.8, $c, [0.75, 0.75, 0.75], 0.5);
        }
    }

    /** Il colore "#rrggbb" nei tre valori 0-1 del PDF; grigio se non si legge. */
    private function rgb(string $hex): array
    {
        if (! preg_match('/^#?([0-9a-f]{6})$/i', trim($hex), $m)) {
            return [0.5, 0.5, 0.5];
        }

        return [hexdec(substr($m[1], 0, 2)) / 255, hexdec(substr($m[1], 2, 2)) / 255, hexdec(substr($m[1], 4, 2)) / 255];
    }

    /** Il numero tondo (1, 2, 5 per dieci alla n) piu' grande che non supera il massimo. */
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
