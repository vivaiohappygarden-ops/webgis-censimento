<?php

namespace App\Services\Maps;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Estratto cartografico per le stampe: mosaico di riquadri OpenStreetMap
 * attorno a un punto, con il segnaposto al centro. Se le mappe non sono
 * raggiungibili (rete chiusa, servizio lento) si restituisce null: il
 * documento esce lo stesso, con al posto della mappa un riquadro vuoto.
 */
class StaticMap
{
    private const TILE = 256;

    private const TIMEOUT = 6;

    /** @return string|null data URI PNG del mosaico */
    public function pngDataUri(float $lat, float $lon, int $zoom = 17, int $cols = 3, int $rows = 2): ?string
    {
        if (! extension_loaded('gd') || ! config('services.osm.tiles_enabled', true)) {
            return null;
        }

        [$xTile, $yTile] = $this->tileOf($lat, $lon, $zoom);
        $canvas = imagecreatetruecolor($cols * self::TILE, $rows * self::TILE);
        $background = imagecolorallocate($canvas, 235, 235, 235);
        imagefilledrectangle($canvas, 0, 0, imagesx($canvas), imagesy($canvas), $background);

        $halfCols = intdiv($cols, 2);
        $halfRows = intdiv($rows, 2);
        $fetched = 0;

        for ($dx = -$halfCols; $dx <= $cols - $halfCols - 1; $dx++) {
            for ($dy = -$halfRows; $dy <= $rows - $halfRows - 1; $dy++) {
                $tile = $this->tile($zoom, (int) floor($xTile) + $dx, (int) floor($yTile) + $dy);
                if ($tile === null) {
                    continue;
                }
                $image = @imagecreatefromstring($tile);
                if ($image === false) {
                    continue;
                }
                imagecopy(
                    $canvas, $image,
                    ($dx + $halfCols) * self::TILE, ($dy + $halfRows) * self::TILE,
                    0, 0, self::TILE, self::TILE,
                );
                imagedestroy($image);
                $fetched++;
            }
        }

        // Nessun riquadro scaricato: meglio nessuna mappa di una grigia
        if ($fetched === 0) {
            imagedestroy($canvas);

            return null;
        }

        $this->drawMarker($canvas, $xTile, $yTile, $halfCols, $halfRows);

        ob_start();
        imagepng($canvas);
        $png = (string) ob_get_clean();
        imagedestroy($canvas);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    /** @return array{0: float, 1: float} coordinate del riquadro (con la parte decimale) */
    private function tileOf(float $lat, float $lon, int $zoom): array
    {
        $n = 2 ** $zoom;
        $x = ($lon + 180) / 360 * $n;
        $latRad = deg2rad($lat);
        $y = (1 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2 * $n;

        return [$x, $y];
    }

    private function tile(int $z, int $x, int $y): ?string
    {
        $max = 2 ** $z;
        if ($x < 0 || $y < 0 || $x >= $max || $y >= $max) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                // La policy OSM richiede di identificare l'applicazione
                'User-Agent' => 'WebGIS-Censimento/1.0 (perizie di stabilita)',
            ])->timeout(self::TIMEOUT)->get("https://tile.openstreetmap.org/{$z}/{$x}/{$y}.png");

            return $response->successful() ? $response->body() : null;
        } catch (\Throwable $e) {
            Log::info('Riquadro di mappa non scaricato: '.$e->getMessage());

            return null;
        }
    }

    /** Croce e cerchio sul punto esatto: sobrio come il resto della stampa. */
    private function drawMarker($canvas, float $xTile, float $yTile, int $halfCols, int $halfRows): void
    {
        $cx = (int) round(($xTile - floor($xTile) + $halfCols) * self::TILE);
        $cy = (int) round(($yTile - floor($yTile) + $halfRows) * self::TILE);

        $red = imagecolorallocate($canvas, 185, 28, 28);
        imagesetthickness($canvas, 3);
        imageellipse($canvas, $cx, $cy, 22, 22, $red);
        imageline($canvas, $cx - 14, $cy, $cx + 14, $cy, $red);
        imageline($canvas, $cx, $cy - 14, $cx, $cy + 14, $red);
    }
}
