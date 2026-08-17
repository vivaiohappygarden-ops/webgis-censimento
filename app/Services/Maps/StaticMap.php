<?php

namespace App\Services\Maps;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Estratto cartografico per le stampe: mosaico di riquadri OpenStreetMap
 * ritagliato attorno a un punto, con il segnaposto ESATTAMENTE al centro.
 * I riquadri si scaricano tutti insieme e restano in cache: la stampa non
 * deve aspettare nove chiamate in fila. Se le mappe non sono raggiungibili
 * (rete chiusa, servizio lento) si restituisce null: il documento esce lo
 * stesso e dichiara che l'estratto non è disponibile.
 */
class StaticMap
{
    private const TILE = 256;

    private const TIMEOUT = 4;

    /** Ritaglio finale attorno al punto: sempre centrato, mai tagliato dal bordo. */
    private const CROP_WIDTH = 512;

    private const CROP_HEIGHT = 400;

    private const CACHE_HOURS = 168;

    /** @return string|null data URI PNG del mosaico ritagliato */
    public function pngDataUri(float $lat, float $lon, int $zoom = 17): ?string
    {
        if (! extension_loaded('gd') || ! config('services.osm.tiles_enabled', true)) {
            return null;
        }

        // Mosaico 3x3: garantisce un intorno sufficiente per centrare il
        // ritaglio qualunque sia la posizione del punto dentro il suo riquadro
        [$xTile, $yTile] = $this->tileOf($lat, $lon, $zoom);
        $x0 = (int) floor($xTile);
        $y0 = (int) floor($yTile);

        $coords = [];
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                $coords[] = [$dx, $dy, $x0 + $dx, $y0 + $dy];
            }
        }

        $tiles = $this->fetchTiles($zoom, $coords);
        if ($tiles === []) {
            return null;
        }

        $canvas = imagecreatetruecolor(3 * self::TILE, 3 * self::TILE);
        $background = imagecolorallocate($canvas, 235, 235, 235);
        imagefilledrectangle($canvas, 0, 0, imagesx($canvas), imagesy($canvas), $background);

        foreach ($coords as [$dx, $dy, , ]) {
            $body = $tiles["{$dx}:{$dy}"] ?? null;
            if ($body === null) {
                continue;
            }
            $image = @imagecreatefromstring($body);
            if ($image === false) {
                continue;
            }
            imagecopy(
                $canvas, $image,
                ($dx + 1) * self::TILE, ($dy + 1) * self::TILE,
                0, 0, self::TILE, self::TILE,
            );
            imagedestroy($image);
        }

        // Posizione esatta del punto nel mosaico, poi ritaglio centrato su di essa
        $cx = (int) round(($xTile - $x0 + 1) * self::TILE);
        $cy = (int) round(($yTile - $y0 + 1) * self::TILE);

        $cropped = imagecrop($canvas, [
            'x' => $cx - intdiv(self::CROP_WIDTH, 2),
            'y' => $cy - intdiv(self::CROP_HEIGHT, 2),
            'width' => self::CROP_WIDTH,
            'height' => self::CROP_HEIGHT,
        ]);
        imagedestroy($canvas);
        if ($cropped === false) {
            return null;
        }

        $this->drawMarker($cropped, intdiv(self::CROP_WIDTH, 2), intdiv(self::CROP_HEIGHT, 2));

        ob_start();
        imagepng($cropped);
        $png = (string) ob_get_clean();
        imagedestroy($cropped);

        return 'data:image/png;base64,'.base64_encode($png);
    }

    /**
     * Scarica i riquadri mancanti in parallelo, tenendo quelli già visti in
     * cache: due perizie sullo stesso giardino non riscaricano nulla.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: int}>  $coords
     * @return array<string, string> chiave "dx:dy"
     */
    private function fetchTiles(int $zoom, array $coords): array
    {
        $max = 2 ** $zoom;
        $found = [];
        $daScaricare = [];

        foreach ($coords as [$dx, $dy, $x, $y]) {
            if ($x < 0 || $y < 0 || $x >= $max || $y >= $max) {
                continue;
            }
            $cached = Cache::get($this->cacheKey($zoom, $x, $y));
            if ($cached !== null) {
                $found["{$dx}:{$dy}"] = $cached;

                continue;
            }
            $daScaricare["{$dx}:{$dy}"] = [$x, $y];
        }

        if ($daScaricare !== []) {
            try {
                $responses = Http::pool(fn ($pool) => collect($daScaricare)
                    ->map(fn (array $xy, string $key) => $pool->as($key)
                        ->withHeaders([
                            // La policy OSM richiede di identificare l'applicazione
                            'User-Agent' => 'WebGIS-Censimento/1.0 (perizie di stabilita)',
                        ])
                        ->timeout(self::TIMEOUT)
                        ->get("https://tile.openstreetmap.org/{$zoom}/{$xy[0]}/{$xy[1]}.png"))
                    ->all());

                foreach ($daScaricare as $key => [$x, $y]) {
                    $response = $responses[$key] ?? null;
                    if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                        $body = $response->body();
                        $found[$key] = $body;
                        Cache::put($this->cacheKey($zoom, $x, $y), $body, now()->addHours(self::CACHE_HOURS));
                    }
                }
            } catch (\Throwable $e) {
                Log::info('Riquadri di mappa non scaricati: '.$e->getMessage());
            }
        }

        return $found;
    }

    private function cacheKey(int $z, int $x, int $y): string
    {
        return "osm_tile:{$z}:{$x}:{$y}";
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

    /** Croce e cerchio sul punto esatto: sobrio come il resto della stampa. */
    private function drawMarker($canvas, int $cx, int $cy): void
    {
        $red = imagecolorallocate($canvas, 185, 28, 28);
        $white = imagecolorallocate($canvas, 255, 255, 255);

        // Alone bianco sotto: il segno resta leggibile anche su sfondo scuro
        imagesetthickness($canvas, 5);
        imageellipse($canvas, $cx, $cy, 24, 24, $white);
        imagesetthickness($canvas, 3);
        imageellipse($canvas, $cx, $cy, 22, 22, $red);
        imageline($canvas, $cx - 14, $cy, $cx + 14, $cy, $red);
        imageline($canvas, $cx, $cy - 14, $cx, $cy + 14, $red);
    }
}
