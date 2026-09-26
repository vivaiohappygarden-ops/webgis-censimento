<?php

namespace App\Services\Photos;

/**
 * Derivato JPEG di una foto per usi di presentazione: SEMPRE ricodificato
 * (la ricodifica GD elimina EXIF e metadati, incluse le coordinate GPS del
 * telefono), ridimensionato oltre la dimensione massima, con soglie di
 * sicurezza su peso e megapixel. null = non derivabile: chi chiama decide
 * se rinunciare alla foto, mai al resto.
 *
 * L'orientamento EXIF si applica prima di buttare gli EXIF: i telefoni
 * salvano spesso i pixel "sdraiati" e un'etichetta che dice al browser di
 * raddrizzarli; senza questo passaggio la copia ricodificata usciva sdraiata
 * (nei PDF e sul portale, che non leggono l'etichetta).
 */
class ImageDerivative
{
    /**
     * La copia d'archivio (dal 26/09/2026): le foto caricate dal computer
     * arrivano intere, anche 5-15 MB l'una, e in archivio ne entra una copia
     * ridotta. Lato lungo 2000 px: basta per la stampa a due foto per pagina
     * della perizia e per lo schermo. L'app di campo riduce gia' sul telefono
     * (1600 px, 200-500 KB): quelle foto restano come sono.
     */
    public const LATO_ARCHIVIO = 2000;

    public const QUALITA_ARCHIVIO = 82;

    /** Sotto questo peso (e dentro il lato massimo) un JPEG dritto si tiene com'e'. */
    public const BYTE_ARCHIVIO = 1_500_000;

    /*
     * Soglie di sicurezza contro le immagini-trappola (un file piccolo che si
     * espande in memoria fino a mettere in ginocchio il server).
     *
     * Vanno tenute in accordo con due numeri:
     * - il limite di caricamento delle foto (15 MB in PhotoController): sotto
     *   quel valore una foto entrerebbe nel programma ma non si potrebbe mai
     *   mostrare, e sparirebbe in silenzio da perizie e portale;
     * - la memoria dei processi PHP (256 MB): GD tiene l'immagine decompressa a
     *   circa 4 byte per pixel, fuori dal conteggio di memory_limit. Misurato
     *   su questo codice, una foto da 24 megapixel porta il picco di memoria
     *   del processo a poco piu' di 100 MB: resta margine, e le derivate del
     *   portale pubblico si calcolano comunque una volta sola (PublicPhotoCache).
     *
     * La soglia precedente era 12 megapixel: una normale foto da telefono
     * (4032 x 3024 = 12,19 megapixel, poco piu' di un megabyte) la superava e
     * veniva scartata senza dire niente a nessuno.
     */
    public const BYTE_MASSIMI = 16 * 1024 * 1024;

    public const PIXEL_MASSIMI = 24_000_000;

    /**
     * La copia da mettere in archivio al caricamento: null se la foto va bene
     * com'e' (JPEG dritto, dentro il lato massimo e sotto il peso di soglia) o
     * se non si riesce a ricodificarla (allora si tiene l'originale: un
     * caricamento non si perde mai); altrimenti il JPEG ridotto e raddrizzato.
     */
    public static function perArchivio(?string $content, int $latoMassimo = self::LATO_ARCHIVIO, int $qualita = self::QUALITA_ARCHIVIO): ?string
    {
        if ($content === null || $content === '') {
            return null;
        }

        $info = @getimagesizefromstring($content);
        if ($info === false) {
            return null;
        }

        $giaBuona = ($info['mime'] ?? null) === 'image/jpeg'
            && max($info[0], $info[1]) <= $latoMassimo
            && strlen($content) <= self::BYTE_ARCHIVIO
            && self::orientamento($content, $info) <= 1;
        if ($giaBuona) {
            return null;
        }

        return self::jpeg($content, $latoMassimo, $qualita);
    }

    public static function jpeg(?string $content, int $maxDimension = 1600, int $quality = 80): ?string
    {
        $source = self::decode($content, $maxDimension, false);
        if ($source === null) {
            return null;
        }

        ob_start();
        imagejpeg($source, null, $quality);
        imagedestroy($source);

        return (string) ob_get_clean() ?: null;
    }

    /**
     * Derivato PNG con trasparenza conservata: serve per gli stemmi dei
     * Comuni, che su fondo colorato devono restare ritagliati.
     */
    public static function png(?string $content, int $maxDimension = 512): ?string
    {
        $source = self::decode($content, $maxDimension, true);
        if ($source === null) {
            return null;
        }

        imagesavealpha($source, true);

        ob_start();
        imagepng($source, null, 6);
        imagedestroy($source);

        return (string) ob_get_clean() ?: null;
    }

    /** Decodifica con le soglie di sicurezza e ridimensionamento. */
    private static function decode(?string $content, int $maxDimension, bool $keepAlpha): ?\GdImage
    {
        if ($content === null || strlen($content) > self::BYTE_MASSIMI) {
            return null;
        }

        $info = @getimagesizefromstring($content);
        if ($info === false || $info[0] * $info[1] > self::PIXEL_MASSIMI) {
            return null;
        }

        $source = @imagecreatefromstring($content);
        if ($source === false) {
            return null;
        }

        $source = self::raddrizza($source, self::orientamento($content, $info));

        // Le misure si leggono dopo il raddrizzamento: una foto in verticale
        // salvata sdraiata scambia larghezza e altezza
        $width = imagesx($source);
        $height = imagesy($source);
        if (max($width, $height) > $maxDimension) {
            $scale = $maxDimension / max($width, $height);
            $newWidth = (int) round($width * $scale);
            $newHeight = (int) round($height * $scale);
            $resized = imagecreatetruecolor($newWidth, $newHeight);

            if ($keepAlpha) {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight,
                    imagecolorallocatealpha($resized, 0, 0, 0, 127));
            }

            imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($source);
            $source = $resized;
        }

        return $source;
    }

    /** L'orientamento EXIF di un JPEG (1-8), 1 se manca o non si legge. */
    private static function orientamento(string $content, array $info): int
    {
        if (($info['mime'] ?? null) !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return 1;
        }

        $stream = fopen('php://memory', 'r+');
        try {
            fwrite($stream, $content);
            rewind($stream);
            $exif = @exif_read_data($stream);
        } catch (\Throwable) {
            $exif = false;
        } finally {
            fclose($stream);
        }

        $valore = (int) ($exif['Orientation'] ?? 1);

        return $valore >= 1 && $valore <= 8 ? $valore : 1;
    }

    /** Applica l'orientamento EXIF ai pixel, cosi' la copia senza EXIF resta dritta. */
    private static function raddrizza(\GdImage $img, int $orientamento): \GdImage
    {
        $ruota = function (\GdImage $i, float $gradi): \GdImage {
            $r = imagerotate($i, $gradi, 0);
            if ($r === false) {
                return $i;
            }
            imagedestroy($i);

            return $r;
        };

        return match ($orientamento) {
            2 => (function () use ($img) {
                imageflip($img, IMG_FLIP_HORIZONTAL);

                return $img;
            })(),
            3 => $ruota($img, 180),
            4 => (function () use ($img) {
                imageflip($img, IMG_FLIP_VERTICAL);

                return $img;
            })(),
            5 => (function () use ($img, $ruota) {
                imageflip($img, IMG_FLIP_VERTICAL);

                return $ruota($img, -90);
            })(),
            6 => $ruota($img, -90),
            7 => (function () use ($img, $ruota) {
                imageflip($img, IMG_FLIP_HORIZONTAL);

                return $ruota($img, -90);
            })(),
            8 => $ruota($img, 90),
            default => $img,
        };
    }
}
