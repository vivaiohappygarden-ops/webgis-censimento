<?php

namespace App\Services\Import;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/**
 * Converte un file geografico caricato in FeatureCollection GeoJSON WGS84.
 *
 * Formati: GeoJSON (.json/.geojson), shapefile zippato (.zip con .shp, .shx,
 * .dbf e obbligatoriamente il .prj), GeoPackage (.gpkg) e KML (.kml). Tutto
 * ciò che non è già GeoJSON passa da ogr2ogr (GDAL) con riproiezione in
 * EPSG:4326. Un file con più livelli non si converte alla cieca: si chiede
 * di esportarne uno alla volta.
 */
class ConvertitoreGeo
{
    /** Estensioni accettate quando non viene passato un elenco più stretto. */
    public const FORMATI = ['zip', 'json', 'geojson', 'gpkg', 'kml'];

    /** Tetto sui byte estratti dallo zip, verificato durante l'estrazione. */
    public const MAX_ESTRATTO = 200 * 1024 * 1024;

    /**
     * @param  list<string>|null  $formati  estensioni ammesse (default: tutte)
     */
    public function aGeoJson(UploadedFile $file, ?array $formati = null): array
    {
        $formati ??= self::FORMATI;
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, $formati, true)) {
            throw ValidationException::withMessages([
                'file' => 'Formato non riconosciuto: si accettano '.implode(', ', array_map(fn ($f) => '.'.$f, $formati)).'.',
            ]);
        }

        if (in_array($extension, ['json', 'geojson'], true)) {
            $decoded = json_decode($file->get(), true);
            if (! is_array($decoded)) {
                throw ValidationException::withMessages(['file' => 'Il file non è un JSON valido.']);
            }

            return $decoded;
        }

        if (! (new \App\Services\Export\CamExporter)->ogr2ogrAvailable()) {
            throw ValidationException::withMessages([
                'file' => 'Import non disponibile per questo formato: sul server manca GDAL (ogr2ogr). Usa il formato GeoJSON.',
            ]);
        }

        $dir = sys_get_temp_dir().'/geo-import-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        try {
            $sorgente = $extension === 'zip'
                ? $this->estraiShapefile($file, $dir)
                : $this->copiaConEstensione($file, $dir, $extension);

            return $this->converti($sorgente, $dir);
        } finally {
            foreach (glob("{$dir}/*") ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    /** Scompatta lo zip di uno shapefile e restituisce il percorso dello .shp. */
    private function estraiShapefile(UploadedFile $file, string $dir): string
    {
        $zip = new \ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            throw ValidationException::withMessages(['file' => 'Archivio zip non leggibile.']);
        }

        // Tetto sulla dimensione DECOMPRESSA: il limite dell'upload vale solo
        // per lo zip compresso, e con DEFLATE una bomba da 50 MB può
        // espandersi a decine di GB saturando memoria e disco
        $totalUncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $totalUncompressed += (int) ($zip->statIndex($i)['size'] ?? 0);
        }
        if ($totalUncompressed > self::MAX_ESTRATTO) {
            throw ValidationException::withMessages([
                'file' => 'Archivio troppo grande una volta estratto (oltre 200 MB).',
            ]);
        }

        // La dimensione dichiarata nell'archivio la scrive chi lo confeziona:
        // il tetto vero si applica sui byte davvero scritti durante
        // l'estrazione, o un archivio che mente riempirebbe il disco
        $scritti = 0;
        $shpNames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            // Solo i componenti shapefile, senza percorsi (protezione zip-slip);
            // nomi normalizzati in minuscolo (gli archivi ESRI storici usano .SHP/.PRJ)
            $name = strtolower(basename($zip->getNameIndex($i)));
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            if (! in_array($ext, ['shp', 'shx', 'dbf', 'prj', 'cpg'], true)) {
                continue;
            }

            $stream = $zip->getStream($zip->getNameIndex($i));
            $out = fopen("{$dir}/{$name}", 'wb');
            $budget = self::MAX_ESTRATTO - $scritti + 1;
            $copiati = ($stream === false || $out === false)
                ? false
                : stream_copy_to_stream($stream, $out, $budget);
            if ($copiati === false) {
                throw ValidationException::withMessages(['file' => 'Estrazione dello zip non riuscita.']);
            }
            fclose($out);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $scritti += $copiati;
            if ($scritti > self::MAX_ESTRATTO) {
                throw ValidationException::withMessages([
                    'file' => 'Archivio troppo grande una volta estratto (oltre 200 MB).',
                ]);
            }

            if ($ext === 'shp') {
                $shpNames[] = $name;
            }
        }
        $zip->close();

        if ($shpNames === []) {
            throw ValidationException::withMessages(['file' => 'Nessun file .shp trovato nello zip.']);
        }
        if (count($shpNames) > 1) {
            throw ValidationException::withMessages([
                'file' => 'Lo zip contiene più shapefile ('.implode(', ', $shpNames).'): caricane uno alla volta.',
            ]);
        }
        $shpName = $shpNames[0];
        if (! file_exists("{$dir}/".substr($shpName, 0, -4).'.prj')) {
            throw ValidationException::withMessages([
                'file' => 'Manca il file .prj (sistema di coordinate): impossibile riproiettare con certezza.',
            ]);
        }

        return "{$dir}/{$shpName}";
    }

    /**
     * GDAL riconosce il driver anche dall'estensione: il file temporaneo di
     * upload non ne ha una, quindi si copia con quella vera.
     */
    private function copiaConEstensione(UploadedFile $file, string $dir, string $extension): string
    {
        $path = "{$dir}/sorgente.{$extension}";
        if (! copy($file->getRealPath(), $path)) {
            throw ValidationException::withMessages(['file' => 'Lettura del file caricato non riuscita.']);
        }

        // GeoPackage e KML possono contenere più livelli: convertire il primo
        // in silenzio importerebbe dati diversi da quelli che l'utente crede
        $livelli = $this->livelli($path);
        if (count($livelli) > 1) {
            throw ValidationException::withMessages([
                'file' => 'Il file contiene più livelli ('.implode(', ', array_slice($livelli, 0, 10)).'): esportane uno alla volta.',
            ]);
        }

        return $path;
    }

    /** I nomi dei livelli del file, letti con ogrinfo. */
    private function livelli(string $path): array
    {
        $process = new Process(['ogrinfo', '-ro', '-q', $path]);

        try {
            $process->setTimeout(60)->run();
        } catch (\Symfony\Component\Process\Exception\ExceptionInterface) {
            throw ValidationException::withMessages(['file' => 'Lettura del file interrotta (file troppo complesso).']);
        }

        if (! $process->isSuccessful()) {
            throw ValidationException::withMessages([
                'file' => 'File non leggibile come dato geografico: '.substr($process->getErrorOutput(), 0, 300),
            ]);
        }

        preg_match_all('/^\d+:\s+(.+?)(?:\s+\([^)]*\))?\s*$/m', $process->getOutput(), $m);

        return $m[1] ?? [];
    }

    private function converti(string $sorgente, string $dir): array
    {
        $jsonPath = "{$dir}/import.geojson";
        $process = new Process([
            'ogr2ogr', '-f', 'GeoJSON',
            '-t_srs', 'EPSG:4326',
            $jsonPath, $sorgente,
        ]);

        try {
            $process->setTimeout(120)->run();
        } catch (\Symfony\Component\Process\Exception\ExceptionInterface) {
            throw ValidationException::withMessages([
                'file' => 'Conversione interrotta (file troppo complesso o strumento non disponibile).',
            ]);
        }

        if (! $process->isSuccessful() || ! file_exists($jsonPath)) {
            throw ValidationException::withMessages([
                'file' => 'Conversione fallita: '.substr($process->getErrorOutput(), 0, 300),
            ]);
        }

        $decoded = json_decode(file_get_contents($jsonPath), true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['file' => 'Conversione non valida.']);
        }

        return $decoded;
    }
}
