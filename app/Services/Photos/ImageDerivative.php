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
    public static function jpeg(?string $content, int $maxDimension = 1600, int $quality = 80): ?string
    {
        if ($content === null || strlen($content) > 8 * 1024 * 1024) {
            return null;
        }

        $info = @getimagesizefromstring($content);
        if ($info === false || $info[0] * $info[1] > 12_000_000) {
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
            imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($source);
            $source = $resized;
        }

        ob_start();
        imagejpeg($source, null, $quality);
        imagedestroy($source);

        return (string) ob_get_clean() ?: null;
    }
}
