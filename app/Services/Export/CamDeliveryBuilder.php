<?php

namespace App\Services\Export;

use App\Models\Photo;
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

        $dir = sys_get_temp_dir().'/cam-delivery-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $zipPath = "{$dir}/consegna.zip";

        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);

        $counts = [];
        $photoNames = [];
        $photoContents = [];
        $missingPhotos = 0;

        foreach (CamExporter::LAYERS as $layer) {
            $collection = $this->exporter->featureCollection($layer, $srid, withAssetIds: true);
            if ($collection['features'] === []) {
                continue;
            }

            $collection = $this->resolvePhotos($collection, $photoNames, $photoContents, $missingPhotos);
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
            $zip->close();

            throw ValidationException::withMessages([
                'delivery' => 'Nessun elemento da consegnare: il censimento è vuoto.',
            ]);
        }

        foreach ($photoContents as $name => $content) {
            $zip->addFromString("FOTO/{$name}", $content);
        }

        $manifest = [
            'standard' => 'Modello dati censimento verde urbano v2.1 (CAM DM 63/2020)',
            'riferimento' => $tag,
            'generata_il' => now()->toIso8601String(),
            'formato' => $format,
            'crs' => $format === 'shapefile' ? "EPSG:{$srid}" : 'WGS84 (CRS84)',
            'layer' => $counts,
            'foto' => count($photoContents),
            'foto_mancanti' => $missingPhotos,
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('LEGGIMI.txt', $this->leggimi($manifest));

        $zip->close();

        return $zipPath;
    }

    /**
     * Le foto referenziate dal campo FOTO finiscono nella cartella FOTO/
     * della consegna; in caso di nomi ripetuti il file viene rinominato
     * col codice censimento e il campo FOTO aggiornato di conseguenza.
     */
    private function resolvePhotos(array $collection, array &$photoNames, array &$photoContents, int &$missingPhotos): array
    {
        $assetIds = collect($collection['features'])
            ->filter(fn ($f) => ! empty($f['properties']['FOTO']) && ! empty($f['properties']['_asset_id']))
            ->map(fn ($f) => $f['properties']['_asset_id'])
            ->values();

        // La stessa regola di scelta dell'export: la prima foto caricata
        $photos = Photo::query()
            ->whereIn('asset_id', $assetIds)
            ->orderBy('created_at')
            ->get()
            ->groupBy('asset_id')
            ->map(fn ($group) => $group->first());

        foreach ($collection['features'] as $i => $feature) {
            $props = $feature['properties'];
            $assetId = $props['_asset_id'] ?? null;
            unset($props['_asset_id']);

            if (! empty($props['FOTO']) && $assetId !== null && ($photo = $photos->get($assetId)) !== null) {
                $delivered = $props['FOTO'];
                if (isset($photoNames[$delivered]) && $photoNames[$delivered] !== $photo->id) {
                    $delivered = trim(($props['OBJ_ID'] ?? substr($assetId, 0, 8)).'_'.$props['FOTO']);
                }

                try {
                    if (! isset($photoNames[$delivered])) {
                        $photoContents[$delivered] = Storage::disk()->get($photo->s3_key);
                        $photoNames[$delivered] = $photo->id;
                    }
                    $props['FOTO'] = $delivered;
                } catch (\Throwable) {
                    // File assente dal disco: la consegna resta valida, il
                    // manifest ne tiene il conto
                    $missingPhotos++;
                    $props['FOTO'] = null;
                }
            }

            $collection['features'][$i]['properties'] = $props;
        }

        return $collection;
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
