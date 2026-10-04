<?php

namespace App\Services\Export;

use App\Models\Organization;
use App\Services\Pdf\Intestazione;
use App\Services\Pdf\PartiComuni;
use App\Services\Pdf\ScrittorePdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * L'elenco degli elementi censiti in PDF: lo stesso elenco filtrato di CSV ed
 * Excel, da stampare o allegare, con l'intestazione dell'organizzazione, i
 * filtri scritti in chiaro e una tabella fitta su A4 orizzontale. Lo scrive
 * ScrittorePdf, non dompdf: dompdf oltre qualche centinaio di righe sfonda la
 * memoria del server, qui ventimila righe sono pochi secondi.
 */
class ElencoPdf
{
    public const TITOLO = 'Elenco degli elementi censiti';

    private const MARGINE = 28.0;

    private const CORPO = 6.8;

    private const INTERLINEA = 8.6;

    private const IMBOTTITURA = 2.4;

    private const GRIGIO = [0.33, 0.33, 0.33];

    private const BORDO = [0.78, 0.78, 0.78];

    public function __construct(private PartiComuni $parti = new PartiComuni) {}

    /**
     * @param  array{organization: ?Organization, colonne: list<array{titolo: string, larghezza: int, tipo: string, decimali?: int}>, righe: list<list<mixed>>, totale: int, tetto: int, filtri: list<string>, stampatoIl: CarbonInterface}  $dati
     */
    public function componi(array $dati): string
    {
        $pdf = new ScrittorePdf;
        $organizzazione = $dati['organization'];
        $pdf->intitola(self::TITOLO, $organizzazione?->name ?? '');
        $pdf->nuovaPagina();
        $x = self::MARGINE;
        $larghezzaUtile = $pdf->larghezza - 2 * self::MARGINE;
        $y = $this->parti->intestazione($pdf, Intestazione::per($organizzazione?->id), $x, 24.0, $larghezzaUtile);

        $pdf->testo($x, $y + 10, self::TITOLO, 11.5, 'grassetto');
        $y += 16;
        $stampato = $dati['stampatoIl']->format('d/m/Y H:i');
        $riga = ($dati['filtri'] ? 'Filtri: '.implode(' - ', $dati['filtri']) : 'Tutti gli elementi')
            .' - '.$this->intero($dati['totale']).' '.($dati['totale'] === 1 ? 'elemento' : 'elementi').' - stampato il '.$stampato;
        foreach ($pdf->spezza($riga, $larghezzaUtile, 7.5) as $r) {
            $pdf->testo($x, $y + 7, $r, 7.5, 'normale', self::GRIGIO);
            $y += 10;
        }
        if ($dati['totale'] > $dati['tetto']) {
            $avviso = sprintf(
                "Il PDF riporta i primi %s elementi su %s, in ordine di codice: per l'elenco completo restringi i filtri o esporta in Excel.",
                $this->intero($dati['tetto']), $this->intero($dati['totale'])
            );
            $righe = $pdf->spezza($avviso, $larghezzaUtile - 12, 7.5);
            $altezza = count($righe) * 10 + 6;
            $y += 3;
            $pdf->rettangolo($x, $y, $larghezzaUtile, $altezza, [1, 0.973, 0.882], [0.788, 0.635, 0.153]);
            foreach ($righe as $i => $r) {
                $pdf->testo($x + 6, $y + 10.5 + $i * 10, $r, 7.5);
            }
            $y += $altezza + 3;
        }
        $y += 6;

        $this->tabella($pdf, $dati['colonne'], $dati['righe'], $x, $y, $larghezzaUtile);
        $this->parti->piede($pdf, ($organizzazione?->name ? self::TITOLO.' - '.$organizzazione->name : self::TITOLO).' - stampato il '.$stampato, self::MARGINE);

        return $pdf->salva();
    }

    /**
     * Le larghezze delle colonne dal contenuto, come fa una tabella HTML. Ogni
     * colonna chiede quanto basta al 95 per cento dei suoi valori (un valore
     * raro e lunghissimo va a capo, non allarga la colonna per tutte le
     * righe), con un tetto oltre il quale il testo va comunque a capo, e non
     * scende sotto il suo minimo: la parola piu' lunga del titolo o del
     * contenuto, una data intera, un numero con le migliaia, cosi' un codice
     * o uno stato non si spezzano mai. I titoli lunghi delle colonne numeriche
     * vanno a capo invece di allargarle: la testata e' una per pagina, le
     * righe sono centinaia. Se avanza spazio lo prendono le colonne di testo;
     * se manca, si stringono solo le colonne piu' larghe, tutte allo stesso
     * punto, finche' la tabella sta nel foglio.
     *
     * @param  list<list<string>>  $celle  i valori gia' formattati
     * @return list<float>
     */
    private function larghezzeColonne(ScrittorePdf $pdf, array $colonne, array $celle, float $disponibile): array
    {
        $pad = 2 * self::IMBOTTITURA + 1;
        $indici = array_keys($colonne);
        $parolaPiuLunga = fn (string $testo, string $stile = 'normale'): float => max(array_map(
            fn (string $p) => $pdf->larghezzaTesto($p, self::CORPO, $stile),
            preg_split('/\s+/', $testo) ?: [$testo]
        ));
        $tettoParola = $pdf->larghezzaTesto(str_repeat('M', 14), self::CORPO);
        $tettoTesto = $pdf->larghezzaTesto(str_repeat('M', 34), self::CORPO);
        $minimi = [];
        $bisogni = [];
        $misure = [];
        foreach ($colonne as $i => $colonna) {
            $atomo = match ($colonna['tipo']) {
                'data' => $pdf->larghezzaTesto('00/00/0000', self::CORPO),
                'numero' => $pdf->larghezzaTesto('0.000,0', self::CORPO),
                default => $pdf->larghezzaTesto('MMM', self::CORPO),
            };
            $minimi[$i] = max($parolaPiuLunga($colonna['titolo'], 'grassetto'), $atomo);
            $bisogni[$i] = $minimi[$i];
            $misure[$i] = [];
        }
        foreach ($celle as $riga) {
            foreach ($indici as $i) {
                if ($riga[$i] === '') {
                    continue;
                }
                $larghezza = $pdf->larghezzaTesto($riga[$i], self::CORPO);
                $misure[$i][] = $larghezza;
                if ($larghezza > $minimi[$i]) {
                    $minimi[$i] = max($minimi[$i], min($tettoParola, $parolaPiuLunga($riga[$i])));
                }
            }
        }
        foreach ($indici as $i) {
            if ($misure[$i]) {
                sort($misure[$i]);
                $bisogni[$i] = max($bisogni[$i], min($tettoTesto, $misure[$i][(int) floor(0.95 * (count($misure[$i]) - 1))]));
            }
            $minimi[$i] += $pad;
            $bisogni[$i] = max($bisogni[$i] + $pad, $minimi[$i]);
        }

        $sommaBisogni = array_sum($bisogni);
        if ($sommaBisogni <= $disponibile) {
            $pesi = array_map(fn (int $i) => in_array($colonne[$i]['tipo'], ['numero', 'data'], true) ? 0.0 : $bisogni[$i], $indici);
            if (array_sum($pesi) <= 0) {
                $pesi = $bisogni;
            }
            $sommaPesi = array_sum($pesi);
            $extra = $disponibile - $sommaBisogni;

            return array_map(fn (int $i) => $bisogni[$i] + $extra * $pesi[$i] / $sommaPesi, $indici);
        }
        $sommaMinimi = array_sum($minimi);
        if ($sommaMinimi >= $disponibile) {
            return array_map(fn (int $i) => $minimi[$i] * $disponibile / $sommaMinimi, $indici);
        }
        // La soglia oltre la quale le colonne larghe si stringono tutte allo stesso punto
        $basso = 0.0;
        $alto = max($bisogni);
        for ($k = 0; $k < 50; $k++) {
            $soglia = ($basso + $alto) / 2;
            $somma = 0.0;
            foreach ($indici as $i) {
                $somma += max($minimi[$i], min($bisogni[$i], $soglia));
            }
            if ($somma > $disponibile) {
                $alto = $soglia;
            } else {
                $basso = $soglia;
            }
        }
        $larghezze = array_map(fn (int $i) => max($minimi[$i], min($bisogni[$i], $basso)), $indici);
        $tagliate = array_values(array_filter($indici, fn (int $i) => $bisogni[$i] > $larghezze[$i] + 0.01));
        $avanzo = $disponibile - array_sum($larghezze);
        foreach ($tagliate ?: $indici as $i) {
            $larghezze[$i] += $avanzo / count($tagliate ?: $indici);
        }

        return $larghezze;
    }

    /** @param  list<array{titolo: string, larghezza: int, tipo: string, decimali?: int}>  $colonne */
    private function tabella(ScrittorePdf $pdf, array $colonne, array $righe, float $x, float $y, float $larghezza): void
    {
        $indici = array_keys($colonne);
        $formattate = array_map(fn (array $riga) => array_map(fn (int $i) => $this->valore($riga[$i] ?? null, $colonne[$i]), $indici), $righe);
        $larghezze = $this->larghezzeColonne($pdf, $colonne, $formattate, $larghezza);
        $limite = $pdf->altezza - 34;
        $testata = function () use ($pdf, $colonne, $larghezze, $x, &$y, $larghezza): void {
            $celle = [];
            $n = 1;
            foreach ($colonne as $i => $colonna) {
                $celle[$i] = $pdf->spezza($colonna['titolo'], $larghezze[$i] - 2 * self::IMBOTTITURA, self::CORPO, 'grassetto');
                $n = max($n, count($celle[$i]));
            }
            $altezza = $n * self::INTERLINEA + 2 * self::IMBOTTITURA;
            $pdf->rettangolo($x, $y, $larghezza, $altezza, [0.933, 0.949, 0.933]);
            $cx = $x;
            foreach ($colonne as $i => $colonna) {
                $pdf->rettangolo($cx, $y, $larghezze[$i], $altezza, null, [0.73, 0.73, 0.73], 0.4);
                // I titoli poggiano sul fondo della cella, come nelle altre tabelle
                $base = $y + $altezza - self::IMBOTTITURA - 2.2 - (count($celle[$i]) - 1) * self::INTERLINEA;
                foreach ($celle[$i] as $k => $r) {
                    $pdf->testo($cx + self::IMBOTTITURA, $base + $k * self::INTERLINEA, $r, self::CORPO, 'grassetto');
                }
                $cx += $larghezze[$i];
            }
            $y += $altezza;
        };
        $testata();
        $inizioRighe = $y;
        if (! $righe) {
            $altezza = self::INTERLINEA + 2 * self::IMBOTTITURA;
            $pdf->rettangolo($x, $y, $larghezza, $altezza, null, self::BORDO, 0.4);
            $pdf->testo($x + self::IMBOTTITURA, $y + $altezza - self::IMBOTTITURA - 2.2, 'Nessun elemento con questi filtri.', self::CORPO, 'normale', self::GRIGIO);

            return;
        }
        foreach ($formattate as $riga) {
            $celle = [];
            $n = 1;
            foreach ($indici as $i) {
                $celle[$i] = $riga[$i] === '' ? [''] : $pdf->spezza($riga[$i], $larghezze[$i] - 2 * self::IMBOTTITURA, self::CORPO);
                $n = max($n, count($celle[$i]));
            }
            $altezza = $n * self::INTERLINEA + 2 * self::IMBOTTITURA;
            if ($y + $altezza > $limite && $y > $inizioRighe) {
                $pdf->nuovaPagina();
                $y = 24.0;
                $testata();
                $inizioRighe = $y;
            }
            $cx = $x;
            foreach ($colonne as $i => $colonna) {
                $pdf->rettangolo($cx, $y, $larghezze[$i], $altezza, null, self::BORDO, 0.4);
                $cx += $larghezze[$i];
            }
            // Una riga di testo per volta attraverso le colonne: il testo del PDF resta leggibile riga per riga
            for ($k = 0; $k < $n; $k++) {
                $base = $y + self::IMBOTTITURA + ($k + 1) * self::INTERLINEA - 2.2;
                $cx = $x;
                foreach ($colonne as $i => $colonna) {
                    $r = $celle[$i][$k] ?? '';
                    if ($r !== '') {
                        if ($colonna['tipo'] === 'numero') {
                            $pdf->testo($cx + self::IMBOTTITURA, $base, $r, self::CORPO, 'normale', ScrittorePdf::NERO, 'destra', $larghezze[$i] - 2 * self::IMBOTTITURA);
                        } else {
                            $pdf->testo($cx + self::IMBOTTITURA, $base, $r, self::CORPO);
                        }
                    }
                    $cx += $larghezze[$i];
                }
            }
            $y += $altezza;
        }
    }

    /** Il valore di una cella formattato all'italiana secondo il tipo della colonna. */
    private function valore(mixed $v, array $colonna): string
    {
        if ($v === null || $v === '') {
            return '';
        }

        return match ($colonna['tipo']) {
            'numero' => is_numeric($v) ? number_format((float) $v, (int) ($colonna['decimali'] ?? 1), ',', '.') : (string) $v,
            'data' => $this->data($v),
            default => trim(preg_replace('/\s+/u', ' ', (string) $v) ?? (string) $v),
        };
    }

    private function data(mixed $v): string
    {
        try {
            return Carbon::parse($v)->timezone('Europe/Rome')->format('d/m/Y');
        } catch (Throwable) {
            return (string) $v;
        }
    }

    private function intero(int $n): string
    {
        return number_format($n, 0, ',', '.');
    }
}
