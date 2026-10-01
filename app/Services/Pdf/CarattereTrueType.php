<?php

namespace App\Services\Pdf;

use RuntimeException;

/**
 * Un carattere TrueType letto quanto basta per incorporarlo in un PDF scritto
 * a mano: la mappa dai codici Unicode ai glifi (tabella cmap), gli avanzamenti
 * (hmtx) e le misure del descrittore (head, hhea, OS/2, post). I file sono
 * quelli che dompdf porta con se' (DejaVu Sans Mono), cosi' un PDF composto
 * senza dompdf usa lo stesso carattere delle altre stampe.
 */
final class CarattereTrueType
{
    /** @var array<string, self> un file si legge una volta per processo */
    private static array $aperti = [];

    public readonly string $percorso;

    public readonly string $nomePostScript;

    public readonly int $unitaPerEm;

    /** @var array{0: int, 1: int, 2: int, 3: int} riquadro del carattere in millesimi di em */
    public readonly array $riquadro;

    public readonly int $ascesa;

    public readonly int $discesa;

    public readonly int $altezzaMaiuscole;

    public readonly float $angoloCorsivo;

    public readonly bool $passoFisso;

    public readonly bool $grassetto;

    private string $dati;

    /** @var array<int, int> codice Unicode => numero del glifo */
    private array $glifi = [];

    /** @var array<int, int> numero del glifo => avanzamento in unita' del carattere */
    private array $avanzamenti = [];

    private int $avanzamentoUltimo = 0;

    /** L'avanzamento comune a tutti i glifi, se il passo e' fisso */
    private int $avanzamentoFisso = 0;

    public static function daFile(string $percorso): self
    {
        return self::$aperti[$percorso] ??= new self($percorso);
    }

    private function __construct(string $percorso)
    {
        $dati = is_file($percorso) ? file_get_contents($percorso) : false;
        if ($dati === false || strlen($dati) < 12) {
            throw new RuntimeException("Carattere non leggibile: {$percorso}");
        }
        $this->percorso = $percorso;
        $this->dati = $dati;

        $tabelle = [];
        $numero = $this->u16(4);
        for ($i = 0; $i < $numero; $i++) {
            $p = 12 + 16 * $i;
            $tabelle[substr($dati, $p, 4)] = $this->u32($p + 8);
        }
        foreach (['head', 'hhea', 'hmtx', 'cmap'] as $tabella) {
            if (! isset($tabelle[$tabella])) {
                throw new RuntimeException("Carattere senza tabella {$tabella}: {$percorso}");
            }
        }

        $head = $tabelle['head'];
        $this->unitaPerEm = max(16, $this->u16($head + 18));
        $m = fn (int $v): int => (int) round($v * 1000 / $this->unitaPerEm);
        $this->riquadro = [$m($this->i16($head + 36)), $m($this->i16($head + 38)), $m($this->i16($head + 40)), $m($this->i16($head + 42))];

        $hhea = $tabelle['hhea'];
        $this->ascesa = $m($this->i16($hhea + 4));
        $this->discesa = $m($this->i16($hhea + 6));
        $metriche = $this->u16($hhea + 34);
        $hmtx = $tabelle['hmtx'];
        for ($g = 0; $g < $metriche; $g++) {
            $this->avanzamenti[$g] = $this->u16($hmtx + 4 * $g);
        }
        $this->avanzamentoUltimo = $this->avanzamenti[$metriche - 1] ?? 0;

        $os2 = $tabelle['OS/2'] ?? null;
        $this->grassetto = $os2 !== null && $this->u16($os2 + 4) >= 600;
        $this->altezzaMaiuscole = $os2 !== null && $this->u16($os2) >= 2 && $this->i16($os2 + 88) > 0
            ? $m($this->i16($os2 + 88))
            : (int) round($this->ascesa * 0.78);

        $post = $tabelle['post'] ?? null;
        $this->angoloCorsivo = $post !== null ? $this->i16($post + 4) + $this->u16($post + 6) / 65536 : 0.0;
        $this->passoFisso = $post !== null && $this->u32($post + 12) !== 0;

        $this->nomePostScript = $this->nomeDaTabella($tabelle['name'] ?? null)
            ?: (preg_replace('/[^A-Za-z0-9-]/', '', pathinfo($percorso, PATHINFO_FILENAME)) ?: 'Carattere');

        $this->leggiCmap($tabelle['cmap']);
        if ($this->passoFisso) {
            $this->avanzamentoFisso = $this->avanzamento($this->glifo(0x61) ?: $this->glifo(0x20));
        }
    }

    /** Il numero del glifo di un codice Unicode (0 = glifo mancante). */
    public function glifo(int $codice): int
    {
        return $this->glifi[$codice] ?? 0;
    }

    /** L'avanzamento di un glifo in unita' del carattere. */
    public function avanzamento(int $glifo): int
    {
        return $this->avanzamenti[$glifo] ?? $this->avanzamentoUltimo;
    }

    /** L'avanzamento di un glifo in millesimi di em, come lo vuole il PDF. */
    public function larghezzaGlifo(int $glifo): int
    {
        return (int) round($this->avanzamento($glifo) * 1000 / $this->unitaPerEm);
    }

    /**
     * I glifi di un testo con i loro codici, nell'ordine.
     *
     * @return list<array{0: int, 1: int}> coppie glifo, codice Unicode
     */
    public function glifiConCodici(string $testo): array
    {
        $coppie = [];
        foreach ($this->codici($testo) as $codice) {
            $coppie[] = [$this->glifi[$codice] ?? 0, $codice];
        }

        return $coppie;
    }

    /** La larghezza di un testo in punti, al corpo dato. */
    public function larghezzaTesto(string $testo, float $corpo): float
    {
        if ($testo === '') {
            return 0.0;
        }
        if ($this->passoFisso) {
            return mb_strlen($testo, 'UTF-8') * $this->avanzamentoFisso * $corpo / $this->unitaPerEm;
        }
        $somma = 0;
        foreach ($this->codici($testo) as $codice) {
            $somma += $this->avanzamento($this->glifi[$codice] ?? 0);
        }

        return $somma * $corpo / $this->unitaPerEm;
    }

    /** Il file del carattere com'e', da incorporare nel PDF. */
    public function programma(): string
    {
        return $this->dati;
    }

    /** @return list<int> i codici Unicode del testo */
    private function codici(string $testo): array
    {
        $utf32 = mb_convert_encoding($testo, 'UTF-32BE', 'UTF-8');

        return $utf32 === '' ? [] : array_values(unpack('N*', $utf32));
    }

    private function leggiCmap(int $cmap): void
    {
        $numero = $this->u16($cmap + 2);
        $formato4 = null;
        $formato12 = null;
        for ($i = 0; $i < $numero; $i++) {
            $p = $cmap + 4 + 8 * $i;
            $piattaforma = $this->u16($p);
            $codifica = $this->u16($p + 2);
            $sotto = $cmap + $this->u32($p + 4);
            if ($sotto + 4 > strlen($this->dati)) {
                continue;
            }
            $formato = $this->u16($sotto);
            if ($formato === 4 && (($piattaforma === 3 && $codifica === 1) || $piattaforma === 0)) {
                $formato4 ??= $sotto;
            }
            if ($formato === 12 && (($piattaforma === 3 && $codifica === 10) || $piattaforma === 0)) {
                $formato12 ??= $sotto;
            }
        }
        if ($formato12 !== null) {
            $this->leggiFormato12($formato12);
        }
        if ($formato4 !== null) {
            $this->leggiFormato4($formato4);
        }
        if (! $this->glifi) {
            throw new RuntimeException("Carattere senza mappa Unicode leggibile: {$this->percorso}");
        }
    }

    private function leggiFormato4(int $p): void
    {
        $segX2 = $this->u16($p + 6);
        $fine = $p + 14;
        $inizio = $fine + $segX2 + 2;
        $delta = $inizio + $segX2;
        $scarto = $delta + $segX2;
        $lunghezza = strlen($this->dati);
        for ($s = 0; $s < $segX2; $s += 2) {
            $da = $this->u16($inizio + $s);
            $a = $this->u16($fine + $s);
            if ($da === 0xFFFF) {
                continue;
            }
            $d = $this->u16($delta + $s);
            $o = $this->u16($scarto + $s);
            for ($c = $da; $c <= $a; $c++) {
                if ($o === 0) {
                    $g = ($c + $d) & 0xFFFF;
                } else {
                    $indirizzo = $scarto + $s + $o + 2 * ($c - $da);
                    if ($indirizzo + 2 > $lunghezza) {
                        continue;
                    }
                    $g = $this->u16($indirizzo);
                    if ($g !== 0) {
                        $g = ($g + $d) & 0xFFFF;
                    }
                }
                if ($g !== 0) {
                    $this->glifi[$c] = $g;
                }
            }
        }
    }

    private function leggiFormato12(int $p): void
    {
        $gruppi = $this->u32($p + 12);
        for ($i = 0; $i < $gruppi; $i++) {
            $q = $p + 16 + 12 * $i;
            if ($q + 12 > strlen($this->dati)) {
                break;
            }
            $da = $this->u32($q);
            $a = min($this->u32($q + 4), 0x10FFFF);
            $g = $this->u32($q + 8);
            if ($a < $da || $a - $da > 65536) {
                continue;
            }
            for ($c = $da; $c <= $a; $c++) {
                $this->glifi[$c] = $g + ($c - $da);
            }
        }
    }

    /** Il nome PostScript (voce 6 della tabella name). */
    private function nomeDaTabella(?int $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $conteggio = $this->u16($name + 2);
        $base = $name + $this->u16($name + 4);
        $trovato = null;
        for ($i = 0; $i < $conteggio; $i++) {
            $r = $name + 6 + 12 * $i;
            if ($this->u16($r + 6) !== 6) {
                continue;
            }
            $piattaforma = $this->u16($r);
            $valore = substr($this->dati, $base + $this->u16($r + 10), $this->u16($r + 8));
            if ($piattaforma === 3 || $piattaforma === 0) {
                $valore = mb_convert_encoding($valore, 'UTF-8', 'UTF-16BE');
            }
            $valore = preg_replace('/[^A-Za-z0-9-]/', '', $valore) ?? '';
            if ($valore !== '') {
                $trovato = $valore;
                if ($piattaforma === 3) {
                    break;
                }
            }
        }

        return $trovato;
    }

    private function u16(int $p): int
    {
        if ($p < 0 || $p + 2 > strlen($this->dati)) {
            throw new RuntimeException("Carattere troncato: {$this->percorso}");
        }

        return unpack('n', $this->dati, $p)[1];
    }

    private function i16(int $p): int
    {
        $v = $this->u16($p);

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function u32(int $p): int
    {
        if ($p < 0 || $p + 4 > strlen($this->dati)) {
            throw new RuntimeException("Carattere troncato: {$this->percorso}");
        }

        return unpack('N', $this->dati, $p)[1];
    }
}
