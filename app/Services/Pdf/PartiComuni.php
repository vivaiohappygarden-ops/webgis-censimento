<?php

namespace App\Services\Pdf;

/**
 * Le parti che tutte le stampe scritte con ScrittorePdf hanno in comune:
 * l'intestazione dell'organizzazione (logo, nome, recapiti), il pie' di pagina
 * con la numerazione e la conversione delle immagini in JPEG, l'unico formato
 * che entra nel PDF senza doverlo decodificare. Stanno qui una volta sola:
 * l'elenco degli elementi e la stampa della mappa escono con la stessa testata.
 */
class PartiComuni
{
    public const GRIGIO = [0.33, 0.33, 0.33];

    public const BORDO = [0.78, 0.78, 0.78];

    /**
     * L'intestazione come nelle altre stampe: logo entro 24 x 18 mm, nome,
     * righe dei recapiti, riga di chiusura. Torna la y da cui continuare.
     *
     * @param  array{nome: string, righe: list<string>, logo: ?string}|null  $intestazione  da Intestazione::per
     */
    public function intestazione(ScrittorePdf $pdf, ?array $intestazione, float $x, float $y, float $larghezza): float
    {
        if (! $intestazione) {
            return $y;
        }
        $xTesto = $x;
        $altezzaLogo = 0.0;
        $logo = $this->logoJpeg($intestazione['logo'] ?? null);
        if ($logo) {
            $scala = min(68 / $logo['larghezza'], 51 / $logo['altezza']);
            $altezzaLogo = $logo['altezza'] * $scala;
            $pdf->immagineJpeg($logo['jpeg'], $x, $y, $logo['larghezza'] * $scala, $altezzaLogo);
            $xTesto = $x + 68 + 8;
        }
        $pdf->testo($xTesto, $y + 10, $intestazione['nome'], 11, 'grassetto');
        $yTesto = $y + 14;
        foreach ($intestazione['righe'] as $riga) {
            foreach ($pdf->spezza($riga, $larghezza - ($xTesto - $x), 7.5) as $r) {
                $pdf->testo($xTesto, $yTesto + 7, $r, 7.5, 'normale', self::GRIGIO);
                $yTesto += 9.5;
            }
        }
        $fine = max($yTesto, $y + $altezzaLogo) + 4;
        $pdf->linea($x, $fine, $x + $larghezza, $fine, 0.6, [0.53, 0.53, 0.53]);

        return $fine + 10;
    }

    /** Il pie' di pagina su tutte le pagine: il testo a sinistra, "Pagina n di N" a destra. */
    public function piede(ScrittorePdf $pdf, string $testo, float $margine): void
    {
        $n = $pdf->numeroPagine();
        for ($i = 0; $i < $n; $i++) {
            $pdf->vaiAPagina($i);
            $yLinea = $pdf->altezza - 24;
            $pdf->linea($margine, $yLinea, $pdf->larghezza - $margine, $yLinea, 0.4, self::BORDO);
            $pdf->testo($margine, $yLinea + 9, $testo, 6.5, 'normale', self::GRIGIO);
            $pdf->testo($margine, $yLinea + 9, sprintf('Pagina %d di %d', $i + 1, $n), 6.5, 'normale', self::GRIGIO, 'destra', $pdf->larghezza - 2 * $margine);
        }
    }

    /**
     * Il logo (PNG, anche con trasparenza) appiattito su bianco in JPEG.
     *
     * @return array{jpeg: string, larghezza: int, altezza: int}|null
     */
    public function logoJpeg(?string $dataUri): ?array
    {
        $byte = $dataUri ? $this->byteDaDataUri($dataUri) : null;

        return $byte ? $this->jpegDa($byte) : null;
    }

    /** I byte di un data URI base64 (o di una stringa base64 nuda); null se non si decodifica. */
    public function byteDaDataUri(string $dati): ?string
    {
        $virgola = strpos($dati, ',');
        $base64 = $virgola !== false && str_starts_with($dati, 'data:') ? substr($dati, $virgola + 1) : $dati;
        $byte = base64_decode(preg_replace('/\s+/', '', $base64) ?? '', true);

        return $byte === false || $byte === '' ? null : $byte;
    }

    /**
     * Un'immagine qualunque leggibile da GD (PNG, JPEG, GIF, WebP) in JPEG
     * appiattito su bianco; un JPEG resta com'e'. Null se non e' un'immagine.
     *
     * @return array{jpeg: string, larghezza: int, altezza: int}|null
     */
    public function jpegDa(string $byte, int $qualita = 90): ?array
    {
        $info = @getimagesizefromstring($byte);
        if (! $info || $info[0] < 1 || $info[1] < 1) {
            return null;
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            return ['jpeg' => $byte, 'larghezza' => (int) $info[0], 'altezza' => (int) $info[1]];
        }
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $immagine = @imagecreatefromstring($byte);
        if (! $immagine) {
            return null;
        }
        $l = imagesx($immagine);
        $a = imagesy($immagine);
        $tela = imagecreatetruecolor($l, $a);
        imagefill($tela, 0, 0, (int) imagecolorallocate($tela, 255, 255, 255));
        imagecopy($tela, $immagine, 0, 0, 0, 0, $l, $a);
        ob_start();
        imagejpeg($tela, null, $qualita);
        $jpeg = (string) ob_get_clean();
        imagedestroy($tela);
        imagedestroy($immagine);

        return $jpeg === '' ? null : ['jpeg' => $jpeg, 'larghezza' => $l, 'altezza' => $a];
    }
}
