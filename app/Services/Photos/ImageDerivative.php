<?php

namespace App\Services\Photos;

/**
 * Derivato JPEG di una foto per usi di presentazione: SEMPRE ricodificato
 * (la ricodifica GD elimina EXIF e metadati, incluse le coordinate GPS del
 * telefono), ridimensionato oltre la dimensione massima, con soglie di
 * sicurezza su peso e megapixel. null = non derivabile: chi chiama decide
 * se rinunciare alla foto, mai al resto.
 */
class ImageDerivative
{
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

        [$width, $height] = $info;
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
}
