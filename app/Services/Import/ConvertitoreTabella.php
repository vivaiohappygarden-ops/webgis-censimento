<?php

namespace App\Services\Import;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/**
 * Ingresso degli elenchi tabellari (.xlsx e .csv) nell'import generico con
 * mappatura delle colonne: molti studi consegnano il censimento come foglio
 * Excel con le coordinate in due colonne, non come shapefile.
 *
 * La lettura passa da ogr2ogr (GDAL, già requisito del programma per import
 * ed export CAM): i driver XLSX e CSV restituiscono la stessa
 * FeatureCollection GeoJSON degli altri formati, con geometria nulla e ogni
 * cella come proprietà. Da lì in poi il flusso è QUELLO ESISTENTE
 * (ImportGenerico): analisi, mappatura guidata, verifica con pre-conteggio,
 * import. L'unico passo in più è la scelta delle colonne X/Y e del sistema
 * di riferimento, applicata da applicaCoordinate() sia in verifica sia in
 * import vero, così anteprima ed esecuzione non possono divergere.
 *
 * Si è scartata la lettura dell'xlsx in PHP (pacchetto in più da installare
 * e mantenere) perché tanto la riproiezione Gauss-Boaga/RDN2008 richiede
 * comunque PROJ/GDAL: i parametri di datum (Monte Mario) non si riscrivono
 * a mano senza sbagliare di decine di metri.
 */
class ConvertitoreTabella
{
    /** Estensioni tabellari gestite qui (il resto va a ConvertitoreGeo). */
    public const FORMATI = ['xlsx', 'csv'];

    /**
     * Numero di riga, NEL FOGLIO, della prima riga di dati: la riga 1 è
     * l'intestazione, che ogr2ogr consuma per i nomi delle colonne. I
     * messaggi di scarto devono usare il numero che l'utente vede in
     * Excel, o "riga #4" lo manderebbe a correggere la riga sbagliata.
     * (Un foglio senza intestazione è fuori standard: lì OGR chiama le
     * colonne field_1, field_2… e si capisce subito che manca.)
     */
    public const PRIMA_RIGA_DATI = 2;

    /**
     * Sistemi di riferimento proposti per le colonne di coordinate: quelli
     * che circolano davvero nelle consegne italiane. Le chiavi sono i codici
     * EPSG e finiscono in una riga di comando ogr2ogr: si accettano SOLO
     * valori di questo elenco, mai un codice libero dal client.
     */
    public const SISTEMI = [
        '4326' => 'WGS84 gradi decimali (GPS, es. 9.19 / 45.46)',
        '3003' => 'Gauss-Boaga fuso Ovest (Monte Mario, EPSG:3003)',
        '3004' => 'Gauss-Boaga fuso Est (Monte Mario, EPSG:3004)',
        '7791' => 'RDN2008 / UTM 32N (EPSG:7791)',
        '7792' => 'RDN2008 / UTM 33N (EPSG:7792)',
        '7793' => 'RDN2008 / UTM 34N (EPSG:7793)',
        '7794' => 'RDN2008 / fuso Italia (EPSG:7794)',
        '32632' => 'WGS84 / UTM 32N (EPSG:32632)',
        '32633' => 'WGS84 / UTM 33N (EPSG:32633)',
        '25832' => 'ETRS89 / UTM 32N (EPSG:25832)',
        '25833' => 'ETRS89 / UTM 33N (EPSG:25833)',
    ];

    /**
     * Nomi di colonna che suggeriscono da soli le coordinate (normalizzati
     * come in ImportGenerico: minuscole, senza separatori). Solo proposta:
     * l'ultima parola resta a chi importa. Niente 'e'/'n' da soli: troppo
     * facile scambiarli per altre colonne.
     */
    private const SINONIMI_X = ['x', 'lon', 'lng', 'long', 'longitude', 'longitudine', 'est', 'east', 'xcoord', 'coordx', 'coordinatax', 'xutm', 'utmx', 'xgb', 'estutm', 'xwgs84'];

    private const SINONIMI_Y = ['y', 'lat', 'latitude', 'latitudine', 'nord', 'north', 'ycoord', 'coordy', 'coordinatay', 'yutm', 'utmy', 'ygb', 'nordutm', 'ywgs84'];

    /** Tetto sul GeoJSON prodotto dalla conversione: un foglio compresso
     * piccolo può espandersi enormemente, e json_decode lo terrebbe tutto
     * in memoria. */
    private const MAX_CONVERTITO = 100 * 1024 * 1024;

    /** Vero se il file va gestito qui (tabella) e non da ConvertitoreGeo. */
    public function gestisce(UploadedFile $file): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());

        // Anche .xls passa da qui: non per importarlo, ma per rifiutarlo
        // con il consiglio giusto invece del generico "formato sconosciuto"
        return in_array($extension, [...self::FORMATI, 'xls'], true);
    }

    /**
     * Legge il foglio come FeatureCollection GeoJSON a geometria nulla
     * (ogni cella diventa una proprietà). Con più fogli si usa il primo e
     * lo si dichiara: importare un foglio a caso in silenzio no.
     *
     * @return array{0: array, 1: list<string>} [geojson, avvisi]
     */
    public function aGeoJson(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'xls') {
            throw ValidationException::withMessages([
                'file' => 'Il formato .xls (Excel 97-2003) non si importa: apri il file in Excel o LibreOffice e salvalo come .xlsx, poi ricaricalo.',
            ]);
        }
        if (! in_array($extension, self::FORMATI, true)) {
            throw ValidationException::withMessages(['file' => 'Formato non tabellare: atteso .xlsx o .csv.']);
        }
        // Un vero .xlsx è un archivio zip ("PK..."): un .xls solo rinominato
        // fallirebbe più avanti con un errore incomprensibile di GDAL
        if ($extension === 'xlsx'
            && substr((string) file_get_contents($file->getRealPath(), false, null, 0, 2), 0, 2) !== 'PK') {
            throw ValidationException::withMessages([
                'file' => 'Il file non è un vero .xlsx (forse un .xls rinominato): risalvalo da Excel o LibreOffice come .xlsx.',
            ]);
        }

        if (! (new \App\Services\Export\CamExporter)->ogr2ogrAvailable()) {
            throw ValidationException::withMessages([
                'file' => 'Import Excel/CSV non disponibile: sul server manca GDAL (ogr2ogr).',
            ]);
        }

        $dir = sys_get_temp_dir().'/tab-import-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        try {
            // GDAL riconosce il driver dall'estensione, e il temporaneo di
            // upload non ne ha una: si copia con quella vera
            $sorgente = "{$dir}/sorgente.{$extension}";
            if (! copy($file->getRealPath(), $sorgente)) {
                throw ValidationException::withMessages(['file' => 'Lettura del file caricato non riuscita.']);
            }

            $avvisi = [];
            $foglio = null;
            if ($extension === 'xlsx') {
                // Un livello per foglio: con più fogli si importa il primo
                // e si dice quale (un .csv ha sempre un livello solo)
                $fogli = (new ConvertitoreGeo)->livelli($sorgente);
                if ($fogli === []) {
                    throw ValidationException::withMessages(['file' => 'Il file Excel non contiene fogli leggibili.']);
                }
                $foglio = $fogli[0];
                if (count($fogli) > 1) {
                    $avvisi[] = "Il file ha più fogli (".implode(', ', array_slice($fogli, 0, 10))."): si importa il primo, \"{$foglio}\". Gli altri sono ignorati.";
                }
            }

            return [$this->converti($sorgente, $dir, $foglio), $avvisi];
        } finally {
            foreach (glob("{$dir}/*") ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    /** Conversione tabella -> GeoJSON: nessuna riproiezione, non c'è ancora
     * nessuna geometria (le coordinate si applicano dopo, dalla mappatura). */
    private function converti(string $sorgente, string $dir, ?string $livello): array
    {
        $jsonPath = "{$dir}/tabella.geojson";
        $comando = ['ogr2ogr', '-f', 'GeoJSON', $jsonPath, $sorgente];
        if ($livello !== null) {
            $comando[] = $livello;
        }

        $process = new Process($comando);

        try {
            $process->setTimeout(120)->run();
        } catch (\Symfony\Component\Process\Exception\ExceptionInterface) {
            throw ValidationException::withMessages(['file' => 'Lettura del foglio interrotta (file troppo grande o complesso).']);
        }

        if (! $process->isSuccessful() || ! file_exists($jsonPath)) {
            throw ValidationException::withMessages([
                'file' => 'Foglio non leggibile: '.substr($process->getErrorOutput(), 0, 300),
            ]);
        }
        if (filesize($jsonPath) > self::MAX_CONVERTITO) {
            throw ValidationException::withMessages(['file' => 'Il foglio è troppo grande una volta convertito (oltre 100 MB).']);
        }

        $decoded = json_decode((string) file_get_contents($jsonPath), true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['file' => 'Conversione del foglio non valida.']);
        }

        return $decoded;
    }

    /**
     * Il blocco "tabellare" della risposta di analisi: proposta delle
     * colonne di coordinate e sistemi di riferimento ammessi. Come per le
     * destinazioni, l'elenco lo detta il server: l'interfaccia non ne
     * tiene una copia sua.
     *
     * @param  list<string>  $nomiColonne
     */
    public function descriviCoordinate(array $nomiColonne): array
    {
        $propostaX = null;
        $propostaY = null;
        foreach ($nomiColonne as $nome) {
            $normalizzato = preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $nome));
            if ($propostaX === null && in_array($normalizzato, self::SINONIMI_X, true)) {
                $propostaX = $nome;
            } elseif ($propostaY === null && in_array($normalizzato, self::SINONIMI_Y, true)) {
                $propostaY = $nome;
            }
        }

        return [
            'proposta_x' => $propostaX,
            'proposta_y' => $propostaY,
            'sistemi' => array_map(
                fn ($codice, $nome) => ['valore' => (string) $codice, 'etichetta' => $nome],
                array_keys(self::SISTEMI),
                self::SISTEMI,
            ),
        ];
    }

    /**
     * Costruisce i punti dalle due colonne scelte, riproiettando in WGS84
     * quando serve. Una riga senza coordinate valide NON viene inventata né
     * persa in silenzio: finisce negli scarti con il suo numero di riga, e
     * ImportGenerico la conteggia nel rapporto (identico in verifica e in
     * import vero, perché questa funzione gira in tutti e due i passaggi).
     *
     * @return array{0: array, 1: list<array{index: int, error: string}>} [geojson con i punti, scarti]
     */
    public function applicaCoordinate(array $geojson, string $colonnaX, string $colonnaY, string $epsg): array
    {
        if (! isset(self::SISTEMI[$epsg])) {
            throw ValidationException::withMessages(['coordinate' => 'Sistema di riferimento non ammesso.']);
        }
        if ($colonnaX === $colonnaY) {
            throw ValidationException::withMessages(['coordinate' => 'Le colonne X e Y delle coordinate devono essere due colonne diverse.']);
        }

        $features = is_array($geojson['features'] ?? null) ? $geojson['features'] : [];
        $scarti = [];
        $validi = [];
        $vistaX = false;
        $vistaY = false;

        foreach ($features as $index => $feature) {
            // Numerazione del foglio (intestazione compresa): vedi PRIMA_RIGA_DATI
            $label = 'riga #'.($index + self::PRIMA_RIGA_DATI);
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $vistaX = $vistaX || array_key_exists($colonnaX, $props);
            $vistaY = $vistaY || array_key_exists($colonnaY, $props);

            $x = $this->coordinata($props[$colonnaX] ?? null);
            $y = $this->coordinata($props[$colonnaY] ?? null);
            if ($x === null || $y === null) {
                $scarti[] = ['index' => $index, 'error' => "{$label}: coordinate mancanti o non numeriche nelle colonne '{$colonnaX}'/'{$colonnaY}': riga scartata."];

                continue;
            }

            // Controllo di verosimiglianza: valori da gradi con un sistema
            // metrico (o viceversa) tradiscono un sistema scelto male; la
            // riproiezione produrrebbe un punto plausibile ma INVENTATO
            $sembranoGradi = abs($x) <= 180 && abs($y) <= 90;
            if ($epsg === '4326' && ! $sembranoGradi) {
                $scarti[] = ['index' => $index, 'error' => "{$label}: coordinate ({$x}, {$y}) fuori dai limiti dei gradi WGS84: forse il sistema di riferimento giusto è un altro. Riga scartata."];

                continue;
            }
            if ($epsg !== '4326' && $sembranoGradi) {
                $scarti[] = ['index' => $index, 'error' => "{$label}: coordinate ({$x}, {$y}) sembrano gradi, ma il sistema scelto (EPSG:{$epsg}) è in metri. Riga scartata."];

                continue;
            }

            $validi[$index] = [$x, $y];
        }

        // Colonna mai vista in nessuna riga: è una scelta sbagliata a monte,
        // non ventimila scarti tutti uguali
        if (($features !== [] && ! $vistaX) || ($features !== [] && ! $vistaY)) {
            $mancante = ! $vistaX ? $colonnaX : $colonnaY;
            throw ValidationException::withMessages([
                'coordinate' => "La colonna '{$mancante}' non compare in nessuna riga del file: controlla la scelta delle colonne delle coordinate.",
            ]);
        }

        if ($epsg !== '4326') {
            $validi = $this->riproietta($validi, $epsg, $scarti);
        }

        foreach ($validi as $index => [$lon, $lat]) {
            $features[$index]['geometry'] = ['type' => 'Point', 'coordinates' => [$lon, $lat]];
        }
        $geojson['features'] = $features;

        return [$geojson, $scarti];
    }

    /** Numero da cella di foglio: virgola decimale italiana ammessa. */
    private function coordinata(mixed $valore): ?float
    {
        if ($valore === null || $valore === '' || ! is_scalar($valore)) {
            return null;
        }
        $normalizzato = str_replace(',', '.', trim((string) $valore));
        if (! is_numeric($normalizzato) || ! is_finite((float) $normalizzato)) {
            return null;
        }

        return (float) $normalizzato;
    }

    /**
     * Riproiezione in blocco via ogr2ogr (PROJ conosce i datum italiani:
     * Monte Mario non si trasforma a mano senza sbagliare di decine di
     * metri). I punti che non tornano finiscono negli scarti, mai inventati.
     *
     * @param  array<int, array{0: float, 1: float}>  $punti  index di riga -> [x, y]
     * @param  list<array{index: int, error: string}>  $scarti
     * @return array<int, array{0: float, 1: float}> index di riga -> [lon, lat]
     */
    private function riproietta(array $punti, string $epsg, array &$scarti): array
    {
        if ($punti === []) {
            return [];
        }
        if (! (new \App\Services\Export\CamExporter)->ogr2ogrAvailable()) {
            throw ValidationException::withMessages([
                'coordinate' => 'Riproiezione non disponibile: sul server manca GDAL (ogr2ogr).',
            ]);
        }

        $dir = sys_get_temp_dir().'/tab-proj-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        try {
            // Ogni punto porta il suo indice di riga: al ritorno si riabbina
            // per indice, e un punto sparito si dichiara come scarto
            $sorgente = "{$dir}/punti.geojson";
            file_put_contents($sorgente, json_encode([
                'type' => 'FeatureCollection',
                'features' => array_map(
                    fn ($index) => [
                        'type' => 'Feature',
                        'properties' => ['i' => $index],
                        'geometry' => ['type' => 'Point', 'coordinates' => $punti[$index]],
                    ],
                    array_keys($punti),
                ),
            ]));

            $jsonPath = "{$dir}/wgs84.geojson";
            $process = new Process([
                'ogr2ogr', '-f', 'GeoJSON', '-skipfailures',
                '-s_srs', "EPSG:{$epsg}", '-t_srs', 'EPSG:4326',
                $jsonPath, $sorgente,
            ]);

            try {
                $process->setTimeout(120)->run();
            } catch (\Symfony\Component\Process\Exception\ExceptionInterface) {
                throw ValidationException::withMessages(['coordinate' => 'Riproiezione interrotta (troppi punti o strumento non disponibile).']);
            }
            if (! $process->isSuccessful() || ! file_exists($jsonPath)) {
                throw ValidationException::withMessages([
                    'coordinate' => 'Riproiezione fallita: '.substr($process->getErrorOutput(), 0, 300),
                ]);
            }

            $decoded = json_decode((string) file_get_contents($jsonPath), true);
            $trasformati = [];
            foreach (is_array($decoded['features'] ?? null) ? $decoded['features'] : [] as $feature) {
                $indice = $feature['properties']['i'] ?? null;
                $coordinate = $feature['geometry']['coordinates'] ?? null;
                if (is_int($indice) && is_array($coordinate) && count($coordinate) >= 2) {
                    $trasformati[$indice] = [(float) $coordinate[0], (float) $coordinate[1]];
                }
            }

            $esito = [];
            foreach ($punti as $index => $originale) {
                $label = 'riga #'.($index + self::PRIMA_RIGA_DATI);
                $punto = $trasformati[$index] ?? null;
                // Fuori dal mondo dopo la riproiezione = coordinate estranee
                // al sistema dichiarato: scartare, mai importare un punto
                // in mezzo all'oceano
                if ($punto === null || abs($punto[0]) > 180 || abs($punto[1]) > 90) {
                    $scarti[] = ['index' => $index, 'error' => "{$label}: coordinate ({$originale[0]}, {$originale[1]}) non riproiettabili da EPSG:{$epsg}: riga scartata."];

                    continue;
                }
                $esito[$index] = $punto;
            }

            return $esito;
        } finally {
            foreach (glob("{$dir}/*") ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }
}
