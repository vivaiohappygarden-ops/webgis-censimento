<?php

namespace App\Services\Pdf;

use InvalidArgumentException;

/**
 * Un PDF scritto a mano, senza dompdf: pagine, testo con carattere TrueType
 * incorporato, linee, rettangoli e immagini JPEG. Serve ai documenti
 * tabellari lunghi (l'elenco degli elementi): dompdf costa quasi mezzo
 * megabyte per riga di tabella e oltre qualche centinaio di righe sfonda la
 * memoria del server, qui ventimila righe sono pochi secondi e pochi megabyte.
 *
 * Coordinate in punti dall'angolo in alto a sinistra (la y cresce verso il
 * basso, come sulla carta). Il carattere e' DejaVu Sans Mono, quello delle
 * altre stampe, preso dai file che dompdf porta con se'; nel PDF entra tutto,
 * con la mappa ToUnicode, cosi' il testo si cerca e si copia.
 */
final class ScrittorePdf
{
    public const A4_ORIZZONTALE = [841.89, 595.28];

    public const A4_VERTICALE = [595.28, 841.89];

    /** Stile => file del carattere, rispetto alla radice del progetto */
    public const CARATTERI = [
        'normale' => 'vendor/dompdf/dompdf/lib/fonts/DejaVuSansMono.ttf',
        'grassetto' => 'vendor/dompdf/dompdf/lib/fonts/DejaVuSansMono-Bold.ttf',
    ];

    public const NERO = [0.07, 0.07, 0.07];

    /** @var list<string> flussi di contenuto, uno per pagina */
    private array $pagine = [];

    private int $corrente = -1;

    /** @var array<string, array<int, int>> stile => glifo => codice Unicode, per la mappa ToUnicode */
    private array $glifiUsati = [];

    /** @var array<string, array<string, string>> stile => testo => stringa esadecimale dei glifi */
    private array $codifiche = [];

    /** @var list<array{dati: string, larghezza: int, altezza: int, canali: int}> */
    private array $immagini = [];

    private string $titolo = '';

    private string $autore = '';

    public function __construct(
        public readonly float $larghezza = self::A4_ORIZZONTALE[0],
        public readonly float $altezza = self::A4_ORIZZONTALE[1],
    ) {}

    /** Titolo e autore scritti nelle proprieta' del documento. */
    public function intitola(string $titolo, string $autore = ''): void
    {
        $this->titolo = $titolo;
        $this->autore = $autore;
    }

    /** Apre una pagina nuova e la rende corrente; torna il suo indice. */
    public function nuovaPagina(): int
    {
        $this->pagine[] = '';

        return $this->corrente = count($this->pagine) - 1;
    }

    /** Torna su una pagina gia' aperta (per i pie' di pagina, scritti alla fine). */
    public function vaiAPagina(int $indice): void
    {
        if (! isset($this->pagine[$indice])) {
            throw new InvalidArgumentException("Pagina inesistente: {$indice}");
        }
        $this->corrente = $indice;
    }

    public function numeroPagine(): int
    {
        return count($this->pagine);
    }

    public function carattere(string $stile = 'normale'): CarattereTrueType
    {
        if (! isset(self::CARATTERI[$stile])) {
            throw new InvalidArgumentException("Stile di carattere sconosciuto: {$stile}");
        }

        return CarattereTrueType::daFile(base_path(self::CARATTERI[$stile]));
    }

    public function larghezzaTesto(string $testo, float $corpo, string $stile = 'normale'): float
    {
        return $this->carattere($stile)->larghezzaTesto($testo, $corpo);
    }

    /**
     * Spezza il testo in righe che stanno nella larghezza data: agli spazi,
     * e dentro la parola se una parola da sola non ci sta.
     *
     * @return list<string>
     */
    public function spezza(string $testo, float $larghezzaMassima, float $corpo, string $stile = 'normale'): array
    {
        $testo = trim(preg_replace('/\s+/u', ' ', $testo) ?? $testo);
        if ($testo === '') {
            return [''];
        }
        if ($this->larghezzaTesto($testo, $corpo, $stile) <= $larghezzaMassima) {
            return [$testo];
        }
        $righe = [];
        $riga = '';
        foreach (explode(' ', $testo) as $parola) {
            $tentativo = $riga === '' ? $parola : $riga.' '.$parola;
            if ($this->larghezzaTesto($tentativo, $corpo, $stile) <= $larghezzaMassima) {
                $riga = $tentativo;

                continue;
            }
            if ($riga !== '') {
                $righe[] = $riga;
                $riga = '';
            }
            while (mb_strlen($parola, 'UTF-8') > 1 && $this->larghezzaTesto($parola, $corpo, $stile) > $larghezzaMassima) {
                $n = $this->caratteriCheStanno($parola, $larghezzaMassima, $corpo, $stile);
                $righe[] = mb_substr($parola, 0, $n, 'UTF-8');
                $parola = mb_substr($parola, $n, null, 'UTF-8');
            }
            $riga = $parola;
        }
        if ($riga !== '') {
            $righe[] = $riga;
        }

        return $righe ?: [''];
    }

    /** Quanti caratteri iniziali della parola stanno nella larghezza data (almeno uno). */
    private function caratteriCheStanno(string $parola, float $larghezzaMassima, float $corpo, string $stile): int
    {
        $da = 1;
        $a = mb_strlen($parola, 'UTF-8');
        while ($da < $a) {
            $meta = intdiv($da + $a + 1, 2);
            if ($this->larghezzaTesto(mb_substr($parola, 0, $meta, 'UTF-8'), $corpo, $stile) <= $larghezzaMassima) {
                $da = $meta;
            } else {
                $a = $meta - 1;
            }
        }

        return $da;
    }

    /**
     * Scrive una riga di testo: x, y sono l'inizio della linea di base (y
     * dall'alto). Con allinea 'destra' o 'centro' il testo si dispone nella
     * larghezza data a partire da x.
     *
     * @param  array{0: float, 1: float, 2: float}  $colore  rosso, verde, blu da 0 a 1
     */
    public function testo(float $x, float $y, string $testo, float $corpo, string $stile = 'normale', array $colore = self::NERO, string $allinea = 'sinistra', float $larghezza = 0): void
    {
        $this->assicuraPagina();
        if ($testo === '') {
            return;
        }
        if ($allinea !== 'sinistra') {
            $l = $this->larghezzaTesto($testo, $corpo, $stile);
            $x += $allinea === 'destra' ? $larghezza - $l : ($larghezza - $l) / 2;
        }
        $this->pagine[$this->corrente] .= sprintf(
            "BT %s rg /%s %s Tf 1 0 0 1 %s %s Tm <%s> Tj ET\n",
            $this->colore($colore), $this->nomeRisorsa($stile), $this->n($corpo), $this->n($x), $this->n($this->altezza - $y), $this->codifica($testo, $stile)
        );
    }

    public function linea(float $x1, float $y1, float $x2, float $y2, float $spessore = 0.5, array $colore = [0.8, 0.8, 0.8]): void
    {
        $this->assicuraPagina();
        $this->pagine[$this->corrente] .= sprintf(
            "%s RG %s w %s %s m %s %s l S\n",
            $this->colore($colore), $this->n($spessore), $this->n($x1), $this->n($this->altezza - $y1), $this->n($x2), $this->n($this->altezza - $y2)
        );
    }

    /** Un rettangolo con angolo in alto a sinistra in x, y: riempito, bordato o tutti e due. */
    public function rettangolo(float $x, float $y, float $l, float $a, ?array $riempimento = null, ?array $bordo = null, float $spessore = 0.5): void
    {
        $this->assicuraPagina();
        if ($riempimento === null && $bordo === null) {
            return;
        }
        $comando = '';
        if ($riempimento !== null) {
            $comando .= $this->colore($riempimento).' rg ';
        }
        if ($bordo !== null) {
            $comando .= $this->colore($bordo).' RG '.$this->n($spessore).' w ';
        }
        $operatore = $riempimento !== null && $bordo !== null ? 'B' : ($riempimento !== null ? 'f' : 'S');
        $this->pagine[$this->corrente] .= $comando.sprintf("%s %s %s %s re %s\n", $this->n($x), $this->n($this->altezza - $y - $a), $this->n($l), $this->n($a), $operatore);
    }

    /** Un'immagine JPEG (a colori o in scala di grigi) dentro il rettangolo dato. */
    public function immagineJpeg(string $jpeg, float $x, float $y, float $l, float $a): void
    {
        $this->assicuraPagina();
        $info = @getimagesizefromstring($jpeg);
        if (! $info || $info[2] !== IMAGETYPE_JPEG) {
            throw new InvalidArgumentException("L'immagine non e' un JPEG");
        }
        $this->immagini[] = ['dati' => $jpeg, 'larghezza' => (int) $info[0], 'altezza' => (int) $info[1], 'canali' => (int) ($info['channels'] ?? 3)];
        $this->pagine[$this->corrente] .= sprintf(
            "q %s 0 0 %s %s %s cm /Im%d Do Q\n",
            $this->n($l), $this->n($a), $this->n($x), $this->n($this->altezza - $y - $a), count($this->immagini)
        );
    }

    /** Il documento completo. */
    public function salva(): string
    {
        if (! $this->pagine) {
            $this->nuovaPagina();
        }
        $oggetti = [];
        $n = 0;
        $nuovo = function (string $corpo) use (&$oggetti, &$n): int {
            $oggetti[++$n] = $corpo;

            return $n;
        };
        $catalogo = $nuovo('');
        $albero = $nuovo('');
        $fuso = date('O');
        $info = $nuovo(sprintf(
            "<< /Title %s /Author %s /Producer %s /CreationDate (D:%s%s'%s') >>",
            $this->stringa($this->titolo), $this->stringa($this->autore), $this->stringa('WebGIS Censimento'), date('YmdHis'), substr($fuso, 0, 3), substr($fuso, 3)
        ));

        $risorseCaratteri = '';
        foreach ($this->glifiUsati as $stile => $glifi) {
            $carattere = $this->carattere($stile);
            $programma = $carattere->programma();
            $file = $nuovo($this->flusso(gzcompress($programma, 6), '/Filter /FlateDecode /Length1 '.strlen($programma)));
            $bandiere = ($carattere->passoFisso ? 1 : 0) | 32 | ($carattere->grassetto ? 1 << 18 : 0);
            $descrittore = $nuovo(sprintf(
                '<< /Type /FontDescriptor /FontName /%s /Flags %d /FontBBox [%d %d %d %d] /ItalicAngle %s /Ascent %d /Descent %d /CapHeight %d /StemV %d /FontFile2 %d 0 R >>',
                $carattere->nomePostScript, $bandiere, $carattere->riquadro[0], $carattere->riquadro[1], $carattere->riquadro[2], $carattere->riquadro[3],
                $this->n($carattere->angoloCorsivo), $carattere->ascesa, $carattere->discesa, $carattere->altezzaMaiuscole, $carattere->grassetto ? 120 : 80, $file
            ));
            // La larghezza predefinita e' la piu' comune; le altre vanno nell'elenco W
            $larghezze = [];
            foreach (array_keys($glifi) as $g) {
                $larghezze[$g] = $carattere->larghezzaGlifo($g);
            }
            $conteggi = array_count_values($larghezze);
            arsort($conteggi);
            $predefinita = (int) array_key_first($conteggi);
            ksort($larghezze);
            $w = '';
            foreach ($larghezze as $g => $l) {
                if ($l !== $predefinita) {
                    $w .= "{$g} [{$l}] ";
                }
            }
            $cid = $nuovo(sprintf(
                '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /DW %d%s /CIDToGIDMap /Identity >>',
                $carattere->nomePostScript, $descrittore, $predefinita, $w !== '' ? ' /W ['.trim($w).']' : ''
            ));
            $toUnicode = $nuovo($this->flusso(gzcompress($this->mappaToUnicode($glifi), 6), '/Filter /FlateDecode'));
            $font = $nuovo(sprintf(
                '<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>',
                $carattere->nomePostScript, $cid, $toUnicode
            ));
            $risorseCaratteri .= sprintf('/%s %d 0 R ', $this->nomeRisorsa($stile), $font);
        }

        $risorseImmagini = '';
        foreach ($this->immagini as $i => $immagine) {
            $spazio = $immagine['canali'] === 1 ? 'DeviceGray' : 'DeviceRGB';
            $numero = $nuovo($this->flusso($immagine['dati'], sprintf(
                '/Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8 /Filter /DCTDecode',
                $immagine['larghezza'], $immagine['altezza'], $spazio
            )));
            $risorseImmagini .= sprintf('/Im%d %d 0 R ', $i + 1, $numero);
        }
        $risorse = sprintf('<< /ProcSet [/PDF /Text /ImageC /ImageB] /Font << %s>> /XObject << %s>> >>', $risorseCaratteri, $risorseImmagini);

        $figli = [];
        foreach ($this->pagine as $contenuto) {
            $flusso = $nuovo($this->flusso(gzcompress($contenuto, 6), '/Filter /FlateDecode'));
            $figli[] = $nuovo(sprintf(
                '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %s %s] /Resources %s /Contents %d 0 R >>',
                $albero, $this->n($this->larghezza), $this->n($this->altezza), $risorse, $flusso
            ));
        }
        $oggetti[$albero] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', array_map(fn (int $k) => "{$k} 0 R", $figli)), count($figli));
        $oggetti[$catalogo] = sprintf('<< /Type /Catalog /Pages %d 0 R >>', $albero);

        $uscita = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $posizioni = [];
        for ($i = 1; $i <= $n; $i++) {
            $posizioni[$i] = strlen($uscita);
            $uscita .= "{$i} 0 obj\n{$oggetti[$i]}\nendobj\n";
        }
        $xref = strlen($uscita);
        $uscita .= 'xref'."\n".'0 '.($n + 1)."\n".'0000000000 65535 f '."\n";
        for ($i = 1; $i <= $n; $i++) {
            $uscita .= sprintf("%010d 00000 n \n", $posizioni[$i]);
        }
        $uscita .= sprintf("trailer\n<< /Size %d /Root %d 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF\n", $n + 1, $catalogo, $info, $xref);

        return $uscita;
    }

    private function assicuraPagina(): void
    {
        if ($this->corrente < 0) {
            $this->nuovaPagina();
        }
    }

    private function nomeRisorsa(string $stile): string
    {
        return 'F'.(array_search($stile, array_keys(self::CARATTERI), true) + 1);
    }

    /** I glifi del testo in esadecimale, due byte per glifo (Identity-H); segna i glifi usati. */
    private function codifica(string $testo, string $stile): string
    {
        if (isset($this->codifiche[$stile][$testo])) {
            return $this->codifiche[$stile][$testo];
        }
        $esadecimale = '';
        foreach ($this->carattere($stile)->glifiConCodici($testo) as [$glifo, $codice]) {
            $esadecimale .= sprintf('%04X', $glifo);
            if ($glifo !== 0) {
                $this->glifiUsati[$stile][$glifo] ??= $codice;
            }
        }
        if (strlen($testo) <= 80) {
            $this->codifiche[$stile][$testo] = $esadecimale;
        }

        return $esadecimale;
    }

    /** @param  array<int, int>  $glifi  glifo => codice Unicode */
    private function mappaToUnicode(array $glifi): string
    {
        ksort($glifi);
        $mappa = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            ."/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            ."/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            ."1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
        foreach (array_chunk($glifi, 100, true) as $blocco) {
            $mappa .= count($blocco)." beginbfchar\n";
            foreach ($blocco as $glifo => $codice) {
                // mb_chr torna "0" per lo zero, che in PHP e' falso: niente scorciatoie con ?:
                $carattere = mb_chr($codice, 'UTF-8');
                $mappa .= sprintf("<%04X> <%s>\n", $glifo, strtoupper(bin2hex(mb_convert_encoding($carattere === false ? '?' : $carattere, 'UTF-16BE', 'UTF-8'))));
            }
            $mappa .= "endbfchar\n";
        }

        return $mappa."endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    }

    private function flusso(string $dati, string $dizionario): string
    {
        return '<< '.$dizionario.' /Length '.strlen($dati).' >>'."\nstream\n".$dati."\nendstream";
    }

    /** Una stringa del PDF: fra parentesi se e' ASCII, altrimenti UTF-16 con il BOM. */
    private function stringa(string $testo): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $testo)) {
            return '('.strtr($testo, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']).')';
        }

        return '<FEFF'.strtoupper(bin2hex(mb_convert_encoding($testo, 'UTF-16BE', 'UTF-8'))).'>';
    }

    private function colore(array $colore): string
    {
        return $this->n((float) ($colore[0] ?? 0)).' '.$this->n((float) ($colore[1] ?? 0)).' '.$this->n((float) ($colore[2] ?? 0));
    }

    private function n(float $v): string
    {
        $s = rtrim(rtrim(sprintf('%.3F', $v), '0'), '.');

        return $s === '-0' || $s === '' ? '0' : $s;
    }
}
