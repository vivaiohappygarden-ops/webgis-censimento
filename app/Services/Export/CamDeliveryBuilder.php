<?php

namespace App\Services\Export;

use App\Models\Photo;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Consegna CAM completa (GIS-DATA-MODEL §6.3.5): un unico zip con tutti i
 * layer non vuoti nominati <riferimento>_<layer>, la cartella FOTO/ con le
 * immagini referenziate dal campo FOTO e il manifest con i conteggi.
 */
class CamDeliveryBuilder
{
    public function __construct(private CamExporter $exporter) {}

    public function build(int $srid, string $format, string $tag): string
    {
        if ($format === 'shapefile' && ! $this->exporter->ogr2ogrAvailable()) {
            throw ValidationException::withMessages([
                'format' => "Consegna shapefile non disponibile: sul server manca GDAL (ogr2ogr). Installa il pacchetto 'gdal-bin' oppure usa il formato geojson.",
            ]);
        }

        // Cartella di lavoro separata dallo zip: la prima si elimina sempre
        // (anche in caso di errore), lo zip sopravvive fino all'invio
        $dir = sys_get_temp_dir().'/cam-delivery-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $zipPath = sys_get_temp_dir().'/cam-consegna-'.bin2hex(random_bytes(6)).'.zip';

        try {
            $zip = new \ZipArchive;
            $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

            $counts = [];
            $photoNames = [];
            $photoFiles = [];
            $missingPhotos = 0;

            foreach (CamExporter::LAYERS as $layer) {
                $collection = $this->exporter->featureCollection($layer, $srid, withAssetIds: true);
                if ($collection['features'] === []) {
                    continue;
                }

                $collection = $this->resolvePhotos($collection, $dir, $photoNames, $photoFiles, $missingPhotos);
                $counts[$layer] = count($collection['features']);

                $base = "{$tag}_{$layer}";
                if ($format === 'shapefile') {
                    foreach ($this->exporter->shapefileParts($collection, $srid, $dir, $base) as $part) {
                        $zip->addFile($part, basename($part));
                    }
                } else {
                    $zip->addFromString("{$base}.geojson", json_encode($collection, JSON_UNESCAPED_UNICODE));
                }
            }

            if ($counts === []) {
                throw ValidationException::withMessages([
                    'delivery' => 'Nessun elemento da consegnare: il censimento è vuoto.',
                ]);
            }

            foreach ($photoFiles as $name => $path) {
                $zip->addFile($path, "FOTO/{$name}");
            }

            $manifest = [
                'standard' => 'Modello dati censimento verde urbano v2.1 (CAM DM 63/2020)',
                'riferimento' => $tag,
                'generata_il' => now()->toIso8601String(),
                'formato' => $format,
                'crs' => $format === 'shapefile' ? "EPSG:{$srid}" : 'WGS84 (CRS84)',
                'layer' => $counts,
                'foto' => count($photoFiles),
                'foto_mancanti' => $missingPhotos,
            ];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $zip->addFromString('LEGGIMI.txt', $this->leggimi($manifest));

            // close() legge ora i file aggiunti: la cartella di lavoro deve
            // esistere ancora (la elimina il finally, che gira dopo)
            $zip->close();

            return $zipPath;
        } catch (\Throwable $e) {
            @unlink($zipPath);

            throw $e;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Le foto referenziate dal campo FOTO vengono copiate (in streaming,
     * mai intere in memoria) nella cartella di lavoro e consegnate in
     * FOTO/; nomi ripetuti vengono disambiguati col codice censimento e il
     * campo FOTO aggiornato. Un file assente dall'archivio azzera il campo
     * e finisce nel conteggio del manifest.
     */
    private function resolvePhotos(array $collection, string $dir, array &$photoNames, array &$photoFiles, int &$missingPhotos): array
    {
        $assetIds = collect($collection['features'])
            ->filter(fn ($f) => ! empty($f['properties']['FOTO']) && ! empty($f['properties']['_asset_id']))
            ->map(fn ($f) => $f['properties']['_asset_id'])
            ->values();

        // La stessa regola di scelta del campo FOTO dell'export: prima la
        // categoria censimento, poi la più recente (spareggio sull'id)
        $photos = Photo::query()
            ->whereIn('asset_id', $assetIds)
            ->orderByRaw("(category = 'census') DESC")
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('asset_id')
            ->map(fn ($group) => $group->first());

        foreach ($collection['features'] as $i => $feature) {
            $props = $feature['properties'];
            $assetId = $props['_asset_id'] ?? null;
            unset($props['_asset_id']);

            if (! empty($props['FOTO']) && $assetId !== null && ($photo = $photos->get($assetId)) !== null) {
                $delivered = $this->deliveredName((string) $props['FOTO'], (string) ($props['OBJ_ID'] ?? substr($assetId, 0, 8)), $photo->id, $photoNames);

                if (isset($photoNames[$delivered])) {
                    // Stessa foto già inclusa da un'altra feature
                    $props['FOTO'] = $delivered;
                } else {
                    $stream = Storage::disk()->readStream($photo->s3_key);
                    if ($stream === null || $stream === false) {
                        // File sparito dall'archivio: la consegna resta
                        // valida e il manifest ne tiene il conto
                        $missingPhotos++;
                        $props['FOTO'] = null;
                    } else {
                        $target = $dir.'/foto-'.count($photoFiles);
                        $out = fopen($target, 'wb');
                        stream_copy_to_stream($stream, $out);
                        fclose($out);
                        fclose($stream);
                        $photoFiles[$delivered] = $target;
                        $photoNames[$delivered] = $photo->id;
                        $props['FOTO'] = $delivered;
                    }
                }
            }

            $collection['features'][$i]['properties'] = $props;
        }

        return $collection;
    }

    /** Nome univoco e sicuro dentro FOTO/ per la foto di questa feature. */
    private function deliveredName(string $original, string $objId, string $photoId, array $photoNames): string
    {
        $name = $this->safeName($original);
        if (! isset($photoNames[$name]) || $photoNames[$name] === $photoId) {
            return $name;
        }

        $prefix = $this->safeName($objId);
        $name = $this->safeName("{$prefix}_{$original}");
        $n = 2;
        while (isset($photoNames[$name]) && $photoNames[$name] !== $photoId) {
            $name = $this->safeName("{$prefix}_{$n}_{$original}");
            $n++;
        }

        return $name;
    }

    /**
     * I nomi dentro lo zip vengono da dati liberi (nome file del client,
     * codice censimento anche importato): niente separatori di percorso,
     * caratteri di controllo, punti iniziali o nomi chilometrici.
     */
    private function safeName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';
        $name = str_replace(['/', '\\'], '_', $name);
        $name = trim(ltrim($name, '. '));
        $name = rtrim($name, '. ');
        if ($name === '') {
            $name = 'foto';
        }
        if (mb_strlen($name) > 100) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 90)
                .($extension !== '' ? ".{$extension}" : '');
        }

        return $name;
    }

    private function leggimi(array $manifest): string
    {
        $lines = [
            'CONSEGNA DEL CENSIMENTO DEL VERDE',
            $manifest['standard'],
            '',
            'Riferimento: '.$manifest['riferimento'],
            'Generata il: '.$manifest['generata_il'],
            'Formato: '.($manifest['formato'] === 'shapefile' ? 'shapefile ('.$manifest['crs'].')' : 'GeoJSON (WGS84)'),
            '',
            'Un file per ogni layer non vuoto (<riferimento>_<layer>):',
        ];
        foreach ($manifest['layer'] as $layer => $count) {
            $lines[] = sprintf('  %s: %d %s', $layer, $count, $count === 1 ? 'elemento' : 'elementi');
        }
        $lines[] = '';
        $lines[] = sprintf('Foto nella cartella FOTO/: %d (referenziate dal campo FOTO)', $manifest['foto']);
        if ($manifest['foto_mancanti'] > 0) {
            $lines[] = sprintf('Attenzione: %d foto referenziate ma assenti dall\'archivio.', $manifest['foto_mancanti']);
        }

        return implode("\n", $lines)."\n";
    }
}
