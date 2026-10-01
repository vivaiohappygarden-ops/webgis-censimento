<?php

namespace Tests\Support;

/**
 * Legge il testo di un PDF scritto da ScrittorePdf: scompone gli oggetti,
 * decomprime i flussi e traduce i glifi con le mappe ToUnicode dei caratteri
 * incorporati. Serve alle prove per leggere che cosa dice il documento, come
 * RaccoglitorePdf fa con le stampe di dompdf. Le scritte alla stessa altezza
 * stanno sulla stessa riga separate da uno spazio, le altre vanno a capo.
 */
final class LettorePdf
{
    /** @return list<string> il testo di ogni pagina */
    public static function pagine(string $pdf): array
    {
        $oggetti = self::oggetti($pdf);
        $pagine = [];
        foreach ($oggetti as $oggetto) {
            if (! preg_match('/\/Type\s*\/Page\b(?!s)/', $oggetto['dizionario'])) {
                continue;
            }
            preg_match('/\/Contents\s+(\d+)\s+0\s+R/', $oggetto['dizionario'], $m);
            $contenuto = self::decodifica($oggetti[(int) ($m[1] ?? 0)] ?? null);
            $mappe = [];
            if (preg_match_all('/\/(F\d+)\s+(\d+)\s+0\s+R/', $oggetto['dizionario'], $caratteri, PREG_SET_ORDER)) {
                foreach ($caratteri as [, $nome, $numero]) {
                    $font = $oggetti[(int) $numero]['dizionario'] ?? '';
                    if (preg_match('/\/ToUnicode\s+(\d+)\s+0\s+R/', $font, $t)) {
                        $mappe[$nome] = self::mappa(self::decodifica($oggetti[(int) $t[1]] ?? null));
                    }
                }
            }
            $pagine[] = self::testoDelFlusso($contenuto, $mappe);
        }

        return $pagine;
    }

    /** Tutto il testo, pagine separate da un a capo. */
    public static function testo(string $pdf): string
    {
        return implode("\n", self::pagine($pdf));
    }

    /** @return array<int, array{dizionario: string, flusso: ?string}> */
    private static function oggetti(string $pdf): array
    {
        $oggetti = [];
        $pos = 0;
        $lunghezza = strlen($pdf);
        while (preg_match('/(\d+) 0 obj\s*/', $pdf, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $numero = (int) $m[1][0];
            $inizio = $m[0][1] + strlen($m[0][0]);
            $fine = $inizio;
            if (substr($pdf, $inizio, 2) === '<<') {
                $profondita = 0;
                for ($i = $inizio; $i < $lunghezza - 1; $i++) {
                    if ($pdf[$i] === '<' && $pdf[$i + 1] === '<') {
                        $profondita++;
                        $i++;
                    } elseif ($pdf[$i] === '>' && $pdf[$i + 1] === '>') {
                        $profondita--;
                        $i++;
                        if ($profondita === 0) {
                            $fine = $i + 1;
                            break;
                        }
                    }
                }
            } else {
                $fine = strpos($pdf, 'endobj', $inizio) ?: $lunghezza;
            }
            $dizionario = substr($pdf, $inizio, $fine - $inizio);
            $flusso = null;
            $dopo = $fine;
            if (preg_match('/\G\s*stream\r?\n/', $pdf, $s, 0, $fine) && preg_match('/\/Length\s+(\d+)/', $dizionario, $l)) {
                $da = $fine + strlen($s[0]);
                $flusso = substr($pdf, $da, (int) $l[1]);
                $dopo = $da + (int) $l[1];
            }
            $oggetti[$numero] = ['dizionario' => $dizionario, 'flusso' => $flusso];
            $pos = strpos($pdf, 'endobj', $dopo) ?: $lunghezza;
        }

        return $oggetti;
    }

    private static function decodifica(?array $oggetto): string
    {
        if (! $oggetto || $oggetto['flusso'] === null) {
            return '';
        }
        if (str_contains($oggetto['dizionario'], '/FlateDecode')) {
            return (string) @gzuncompress($oggetto['flusso']);
        }

        return $oggetto['flusso'];
    }

    /** @return array<int, string> glifo => testo */
    private static function mappa(string $cmap): array
    {
        $mappa = [];
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocchi)) {
            foreach ($blocchi[1] as $blocco) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $blocco, $coppie, PREG_SET_ORDER)) {
                    foreach ($coppie as [, $glifo, $unicode]) {
                        $mappa[hexdec($glifo)] = mb_convert_encoding(hex2bin($unicode) ?: '', 'UTF-8', 'UTF-16BE');
                    }
                }
            }
        }

        return $mappa;
    }

    /** @param  array<string, array<int, string>>  $mappe */
    private static function testoDelFlusso(string $contenuto, array $mappe): string
    {
        $righe = [];
        $riga = '';
        $yCorrente = null;
        $mappa = [];
        preg_match_all('/\/(F\d+)\s+[\d.]+\s+Tf|1 0 0 1 ([\d.-]+) ([\d.-]+) Tm|<([0-9A-Fa-f]*)>\s*Tj/', $contenuto, $gettoni, PREG_SET_ORDER);
        foreach ($gettoni as $g) {
            if (($g[1] ?? '') !== '') {
                $mappa = $mappe[$g[1]] ?? [];

                continue;
            }
            if (($g[2] ?? '') !== '') {
                $y = (float) $g[3];
                if ($yCorrente !== null && abs($y - $yCorrente) > 0.5 && trim($riga) !== '') {
                    $righe[] = trim($riga);
                    $riga = '';
                }
                $yCorrente = $y;

                continue;
            }
            if (isset($g[4])) {
                $testo = '';
                foreach (str_split($g[4], 4) as $glifo) {
                    $testo .= $mappa[hexdec($glifo)] ?? '?';
                }
                $riga .= ($riga === '' ? '' : ' ').$testo;
            }
        }
        if (trim($riga) !== '') {
            $righe[] = trim($riga);
        }

        return implode("\n", $righe);
    }
}
