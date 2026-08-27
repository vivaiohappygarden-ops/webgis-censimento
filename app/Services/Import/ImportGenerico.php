<?php

namespace App\Services\Import;

use App\Models\Area;
use App\Models\Asset;
use App\Models\CatalogObjectType;
use App\Models\Tree;
use App\Support\Geometry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Import di un file geografico QUALSIASI, con associazione delle colonne:
 * il file di un altro fornitore non ha i nomi del nostro tracciato, quindi
 * prima si analizza (colonne, esempi, anteprima, proposta automatica), poi
 * l'utente dice quale colonna corrisponde a quale campo nostro, e solo dopo
 * si importa (dry-run obbligatorio, transazionale come gli altri import).
 *
 * L'analisi conserva il file convertito su disco e restituisce un gettone:
 * anteprima, verifica e import non richiedono di ricaricare il file.
 */
class ImportGenerico
{
    /** Un censimento comunale intero supera i 5.000 del canale CAM. */
    public const MAX_FEATURES = 20000;

    private const RIGHE_ANTEPRIMA = 5;

    /** Quanto vive un'analisi conservata prima di dover ricaricare il file. */
    private const SCADENZA_SECONDI = 3600;

    /**
     * Le destinazioni possibili di una colonna. 'albero' finisce nella
     * scheda albero per i tipi che la richiedono e negli attributi standard
     * MD per le altre vegetazioni (stessa strada dell'import CAM).
     */
    public const DESTINAZIONI = [
        'codice_censimento' => ['label' => 'Codice censimento (etichetta)'],
        'codice_catalogo' => ['label' => 'Codice del nostro catalogo (es. P103108)'],
        'note' => ['label' => 'Note'],
        'data_rilievo' => ['label' => 'Data del rilievo'],
        'numero_pianta' => ['label' => 'Numero pianta', 'albero' => 'plant_number'],
        'genere' => ['label' => 'Genere', 'albero' => 'genus', 'attributo' => 'genere'],
        'specie' => ['label' => 'Specie', 'albero' => 'species', 'attributo' => 'specie'],
        'varieta' => ['label' => 'Varietà / cultivar', 'albero' => 'cultivar', 'attributo' => 'varieta'],
        'altezza_m' => ['label' => 'Altezza (m)', 'albero' => 'height_m', 'attributo' => 'altezza_m', 'numero' => true],
        'diametro_tronco_cm' => ['label' => 'Diametro tronco (cm)', 'albero' => 'dbh_cm', 'numero' => true],
        'diametro_chioma_m' => ['label' => 'Diametro chioma (m)', 'albero' => 'crown_diameter_m', 'numero' => true],
        'larghezza_m' => ['label' => 'Larghezza (m)', 'attributo' => 'larghezza_m', 'numero' => true],
    ];

    /**
     * La scheda albero viaggia sempre con TUTTE le chiavi (null comprese):
     * l'insert a blocchi prende le colonne dalla prima riga, e righe con
     * insiemi diversi farebbero slittare i valori di colonna.
     */
    private const ALBERO_VUOTO = [
        'plant_number' => null, 'genus' => null, 'species' => null, 'cultivar' => null,
        'height_m' => null, 'dbh_cm' => null, 'crown_diameter_m' => null,
    ];

    /**
     * Nomi di colonna che suggeriscono da soli la destinazione (normalizzati
     * senza maiuscole né separatori). La proposta è solo un punto di
     * partenza: l'ultima parola resta a chi importa.
     */
    private const SINONIMI = [
        'codice_censimento' => ['objid', 'idpianta', 'idalbero', 'codicecensimento', 'censimento', 'etichetta', 'targa', 'cartellino', 'numalbero', 'codalbero', 'matricola'],
        'note' => ['note', 'notes', 'osservazioni', 'annotazioni'],
        'data_rilievo' => ['dataril', 'datarilievo', 'data', 'rilievo', 'surveydate'],
        'numero_pianta' => ['pt', 'npianta', 'numpianta', 'numeropianta'],
        'genere' => ['genere', 'genus'],
        'specie' => ['specie', 'species', 'speciealb', 'essenza'],
        'varieta' => ['varieta', 'cultivar', 'var'],
        'altezza_m' => ['hm', 'h', 'altezza', 'altezzam', 'height', 'altm'],
        'diametro_tronco_cm' => ['diamtronc', 'diamtronco', 'diametrotronco', 'dbh', 'dbhcm', 'diamcm', 'dtronco', 'diametro'],
        'diametro_chioma_m' => ['diamchiom', 'diamchioma', 'diametrochioma', 'chioma', 'crown', 'dchioma'],
        'larghezza_m' => ['largm', 'larghezza', 'larghezzam'],
    ];

    // ---- Analisi ----------------------------------------------------------

    /**
     * Legge la FeatureCollection e racconta cosa contiene: colonne con
     * esempi, geometrie, anteprima delle prime righe e proposta di
     * mappatura. Conserva il file convertito e restituisce il gettone.
     */
    public function analizza(array $geojson, string $tenantId): array
    {
        if (($geojson['type'] ?? null) !== 'FeatureCollection' || ! is_array($geojson['features'] ?? null)) {
            throw ValidationException::withMessages(['file' => 'Il file deve contenere una FeatureCollection GeoJSON.']);
        }

        $features = $geojson['features'];
        if ($features === []) {
            throw ValidationException::withMessages(['file' => 'Il file non contiene nessun elemento.']);
        }
        if (count($features) > self::MAX_FEATURES) {
            throw ValidationException::withMessages([
                'file' => 'Troppi elementi ('.count($features).'): il limite per import è '.self::MAX_FEATURES.'.',
            ]);
        }

        $colonne = [];
        $geometrie = [];
        foreach ($features as $feature) {
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            foreach ($props as $nome => $valore) {
                $colonne[$nome] ??= ['nome' => $nome, 'esempi' => []];
                $testo = LetturaValori::testo($valore);
                if ($testo !== null && count($colonne[$nome]['esempi']) < 3
                    && ! in_array($testo, $colonne[$nome]['esempi'], true)) {
                    $colonne[$nome]['esempi'][] = mb_substr($testo, 0, 60);
                }
            }
            $tipo = $feature['geometry']['type'] ?? 'assente';
            $geometrie[$tipo] = ($geometrie[$tipo] ?? 0) + 1;
        }

        $anteprima = [];
        foreach (array_slice($features, 0, self::RIGHE_ANTEPRIMA) as $feature) {
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $anteprima[] = array_map(
                // Il GeoJSON ammette valori annidati: in anteprima si dichiara
                // il dato composto invece di far esplodere il cast a stringa
                fn ($v) => $v === null ? null : (is_scalar($v) ? mb_substr((string) $v, 0, 60) : '[dato composto]'),
                $props,
            );
        }

        return [
            'token' => $this->conserva($geojson, $tenantId),
            'totale' => count($features),
            'geometrie' => $geometrie,
            'colonne' => array_values($colonne),
            'anteprima' => $anteprima,
            'proposta' => $this->proposta(array_values($colonne)),
            // Le destinazioni le detta il server: l'interfaccia non ne tiene
            // una copia sua che prima o poi divergerebbe
            'destinazioni' => array_map(
                fn ($chiave, $def) => ['valore' => $chiave, 'etichetta' => $def['label']],
                array_keys(self::DESTINAZIONI),
                self::DESTINAZIONI,
            ),
            'limite' => self::MAX_FEATURES,
        ];
    }

    /**
     * Proposta automatica: prima si guardano i VALORI (una colonna con i
     * codici del nostro catalogo si riconosce da sola), poi i nomi.
     */
    private function proposta(array $colonne): array
    {
        $codici = CatalogObjectType::query()->pluck('code')->flip();
        $proposta = [];
        $usate = [];

        foreach ($colonne as $colonna) {
            $esempi = $colonna['esempi'];
            if ($esempi !== [] && ! isset($usate['codice_catalogo'])) {
                $nostri = count(array_filter($esempi, fn ($v) => $codici->has($v)));
                if ($nostri === count($esempi)) {
                    $proposta[$colonna['nome']] = 'codice_catalogo';
                    $usate['codice_catalogo'] = true;

                    continue;
                }
            }

            $normalizzato = preg_replace('/[^a-z0-9]/', '', mb_strtolower($colonna['nome']));
            foreach (self::SINONIMI as $destinazione => $nomi) {
                if (! isset($usate[$destinazione]) && in_array($normalizzato, $nomi, true)) {
                    $proposta[$colonna['nome']] = $destinazione;
                    $usate[$destinazione] = true;

                    break;
                }
            }
        }

        return $proposta;
    }

    // ---- Import -----------------------------------------------------------

    /**
     * @param  array<string, string>  $mappatura  colonna del file -> destinazione
     * @param  string  $esistenti  'salta' (come il canale CAM) o 'aggiorna'
     */
    public function importa(
        array $geojson,
        Area $area,
        array $mappatura,
        ?CatalogObjectType $defaultType,
        string $esistenti,
        bool $dryRun = true,
    ): array {
        $this->validaMappatura($mappatura);

        $features = $geojson['features'] ?? [];
        $tenantId = $area->tenant_id;
        $typesByCode = CatalogObjectType::query()->get()->keyBy('code');

        $errors = [];
        $warnings = [];
        $daInserire = [];
        $daAggiornare = [];
        $censusInFile = [];
        // Valori mappati che il tipo scelto non sa dove mettere: contati,
        // non persi in silenzio
        $ignorati = [];

        // colonna per destinazione (una sola colonna per destinazione)
        $col = array_flip($mappatura);

        // Per aggiornare serve la colonna del codice censimento: senza,
        // le schede esistenti non si riconoscono e ogni reimport
        // duplicherebbe tutto in silenzio
        if ($esistenti === 'aggiorna' && ! isset($col['codice_censimento'])) {
            throw ValidationException::withMessages([
                'mappatura' => 'Per aggiornare le schede esistenti serve una colonna mappata su "Codice censimento": senza, le schede non si riconoscono.',
            ]);
        }

        // Le schede esistenti si caricano PRIMA del giro: con "aggiorna" la
        // geometria e i campi si valutano contro il tipo VERO della scheda,
        // non contro quello dedotto dal file (che potrebbe essere un altro)
        $esistentiDb = collect();
        if (isset($col['codice_censimento'])) {
            $codici = [];
            foreach ($features as $feature) {
                $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
                $codice = LetturaValori::testo($props[$col['codice_censimento']] ?? null);
                if ($codice !== null) {
                    $codici[] = $codice;
                }
            }
            if ($codici !== []) {
                $esistentiDb = Asset::query()->with('objectType')
                    ->whereIn('census_code', array_unique($codici))
                    ->get()->keyBy('census_code');
            }
        }

        foreach ($features as $index => $feature) {
            $label = 'riga #'.($index + 1);
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];

            // Un valore composto (lista o oggetto del GeoJSON) non è un dato
            // per la scheda: si avvisa e si ignora, qualunque sia il campo
            $valore = function (string $dest) use ($col, $props, $label, &$warnings) {
                $v = isset($col[$dest]) ? ($props[$col[$dest]] ?? null) : null;
                if ($v !== null && ! is_scalar($v)) {
                    $warnings[] = "{$label}: ".$col[$dest].': valore composto (lista o oggetto), ignorato.';

                    return null;
                }

                return $v;
            };

            $censusCode = LetturaValori::testo($valore('codice_censimento'));
            if ($censusCode !== null) {
                if (isset($censusInFile[$censusCode])) {
                    $errors[] = ['index' => $index, 'error' => "{$label}: codice censimento '{$censusCode}' duplicato nel file."];

                    continue;
                }
                $censusInFile[$censusCode] = $index;
            }

            // Codice già in banca dati: si salta (come il canale CAM) o si
            // aggiorna la scheda esistente, secondo la scelta fatta
            $esistente = $censusCode !== null ? $esistentiDb->get($censusCode) : null;
            if ($esistente !== null && $esistenti === 'salta') {
                $errors[] = ['index' => $index, 'error' => "{$label}: codice censimento '{$censusCode}' già presente nel censimento."];

                continue;
            }

            // Tipo: dalla colonna del codice catalogo se mappata, altrimenti
            // dal tipo predefinito. Un codice scritto ma sconosciuto è un
            // errore di riga, non un ripiego silenzioso sul predefinito
            $type = $defaultType;
            $codice = LetturaValori::testo($valore('codice_catalogo'));
            if ($codice !== null) {
                $type = $typesByCode->get($codice);
                if ($type === null) {
                    $errors[] = ['index' => $index, 'error' => "{$label}: codice catalogo '{$codice}' non presente nel nostro catalogo."];

                    continue;
                }
            }
            // Una scheda esistente tiene il SUO tipo: l'aggiornamento non lo
            // cambia, e geometria e campi vanno giudicati contro quello
            if ($esistente !== null) {
                $type = $esistente->objectType;
            }
            if ($type === null) {
                $errors[] = ['index' => $index, 'error' => "{$label}: nessun codice catalogo nella riga e nessun tipo predefinito scelto."];

                continue;
            }

            // I perimetri delle aree di gestione non diventano elementi
            // (stessa regola del canale CAM: si gestiscono nel Territorio)
            if ($type->code === 'S325500') {
                $warnings[] = "{$label}: S325500 (limite area di gestione) ignorato: i perimetri si gestiscono nel Territorio.";

                continue;
            }

            $geometry = $feature['geometry'] ?? null;
            if (! is_array($geometry) || empty($geometry['type'])) {
                $errors[] = ['index' => $index, 'error' => "{$label}: geometria mancante."];

                continue;
            }
            // Gli shapefile spesso dichiarano Multi* anche per geometrie a
            // parte singola: si scarta l'involucro, non la riga
            $geometry = $this->semplifica($geometry);
            $allowed = array_map('strtoupper', $type->allowedGeometryTypes());
            if (! in_array(strtoupper($geometry['type']), $allowed, true)) {
                $errors[] = ['index' => $index, 'error' => "{$label}: geometria {$geometry['type']} non ammessa per {$type->code}."];

                continue;
            }

            try {
                $ewkb = Geometry::toEwkb($geometry);
            } catch (ValidationException $e) {
                $errors[] = ['index' => $index, 'error' => "{$label}: ".collect($e->errors())->flatten()->first()];

                continue;
            }

            $note = LetturaValori::testo($valore('note'));
            $dataRilievo = LetturaValori::data($valore('data_rilievo'), "{$label}: data rilievo", $warnings);

            // Valori della vegetazione: scheda albero per i tipi arborei,
            // attributi standard MD per siepi e superfici verdi. Le chiavi
            // dell'albero sono SEMPRE tutte presenti: righe con insiemi di
            // colonne diversi nello stesso insert farebbero slittare i
            // valori di colonna
            $albero = null;
            $attributi = [];
            foreach (self::DESTINAZIONI as $dest => $def) {
                if (! isset($col[$dest]) || (! isset($def['albero']) && ! isset($def['attributo']))) {
                    continue;
                }
                $grezzo = $props[$col[$dest]] ?? null;
                // Il GeoJSON ammette liste e oggetti dentro le proprietà:
                // non sono un valore per la scheda e non si perdono zitti
                if ($grezzo !== null && ! is_scalar($grezzo)) {
                    $warnings[] = "{$label}: ".$col[$dest].': valore composto (lista o oggetto), ignorato.';

                    continue;
                }
                $letto = ! empty($def['numero'])
                    ? LetturaValori::numero($grezzo, "{$label}: ".$col[$dest], $warnings)
                    : LetturaValori::testo($grezzo);
                if ($letto === null) {
                    continue;
                }
                if ($type->requires_tree_record && isset($def['albero'])) {
                    $albero ??= self::ALBERO_VUOTO;
                    $albero[$def['albero']] = $letto;
                } elseif (! $type->requires_tree_record && isset($def['attributo'])) {
                    $attributi[$def['attributo']] = $letto;
                } else {
                    $ignorati[$dest] ??= ['colonna' => $col[$dest], 'righe' => 0];
                    $ignorati[$dest]['righe']++;
                }
            }

            if ($albero !== null) {
                $albero = $this->validaAlbero($albero, $index, $label, $errors);
                if ($albero === false) {
                    continue;
                }
            }

            $riga = [
                'census_code' => $censusCode,
                'type' => $type,
                'ewkb' => $ewkb,
                'note' => $note,
                'data_rilievo' => $dataRilievo,
                'albero' => $albero,
                'attributi' => $attributi,
                'esistente_id' => $esistente?->id,
                'index' => $index,
                'label' => $label,
            ];

            if ($esistente !== null) {
                $daAggiornare[] = $riga;
            } else {
                $daInserire[] = $riga;
            }
        }

        foreach ($ignorati as $dest => $info) {
            $warnings[] = "Colonna '{$info['colonna']}' (".self::DESTINAZIONI[$dest]['label'].'): il tipo della riga non ha questo campo, valore ignorato su '
                .($info['righe'] === 1 ? '1 riga.' : "{$info['righe']} righe.");
        }

        // Righe pronte per l'insert (fuori dal partition: servono gli id)
        $assetRows = [];
        $treeRows = [];
        $customFieldNeeds = [];
        foreach ($daInserire as $riga) {
            $assetId = (string) Str::uuid7();
            $assetRows[] = [
                'id' => $assetId,
                'tenant_id' => $tenantId,
                'area_id' => $area->id,
                'object_type_id' => $riga['type']->id,
                'census_code' => $riga['census_code'],
                'status' => 'active',
                'geom' => $riga['ewkb'],
                'survey_method' => 'shapefile_import',
                'surveyed_at' => $riga['data_rilievo'],
                'attributes' => $riga['attributi'] === [] ? '{}' : (json_encode($riga['attributi']) ?: '{}'),
                'notes' => $riga['note'],
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($riga['type']->requires_tree_record) {
                $treeRows[] = [
                    'asset_id' => $assetId,
                    'tenant_id' => $tenantId,
                    ...($riga['albero'] ?? self::ALBERO_VUOTO),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            foreach (array_keys($riga['attributi']) as $key) {
                if (isset(CamImporter::MD_CUSTOM_FIELDS[$key])) {
                    $customFieldNeeds["{$riga['type']->id}|{$key}"] = ['object_type_id' => $riga['type']->id, 'key' => $key];
                }
            }
        }
        foreach ($daAggiornare as $riga) {
            foreach (array_keys($riga['attributi']) as $key) {
                if (isset(CamImporter::MD_CUSTOM_FIELDS[$key])) {
                    $customFieldNeeds["{$riga['type']->id}|{$key}"] = ['object_type_id' => $riga['type']->id, 'key' => $key];
                }
            }
        }

        $imported = 0;
        $aggiornati = 0;
        if (! $dryRun && ($assetRows !== [] || $daAggiornare !== [])) {
            DB::transaction(function () use ($assetRows, $treeRows, $daAggiornare, $customFieldNeeds, $tenantId, &$imported, &$aggiornati) {
                CamImporter::assicuraCampiMd($customFieldNeeds, $tenantId);

                foreach (array_chunk($assetRows, 200) as $chunk) {
                    Asset::insert($chunk);
                    $imported += count($chunk);
                }
                foreach (array_chunk($treeRows, 200) as $chunk) {
                    Tree::insert($chunk);
                }

                foreach ($daAggiornare as $riga) {
                    $this->aggiorna($riga);
                    $aggiornati++;
                }
            });
        }

        return [
            'total' => count($features),
            'importable' => count($assetRows),
            'updatable' => count($daAggiornare),
            'imported' => $imported,
            'updated' => $aggiornati,
            'skipped' => count($features) - count($assetRows) - count($daAggiornare),
            'errors' => array_slice($errors, 0, 50),
            'errors_total' => count($errors),
            'warnings' => array_slice($warnings, 0, 50),
            'warnings_total' => count($warnings),
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Aggiornamento di un elemento esistente riconosciuto dal codice
     * censimento: geometria, note, data rilievo, scheda albero e attributi
     * mappati. L'elemento resta nella sua area e con il suo tipo.
     *
     * Ordine delle scritture obbligato (vedi CLAUDE.md): prima si prepara la
     * scheda albero senza salvarla, poi si salva assets (lì scatta la
     * fotografia di versione), e solo dopo si salva l'albero.
     */
    private function aggiorna(array $riga): void
    {
        $asset = Asset::query()->with('tree')->lockForUpdate()->find($riga['esistente_id']);
        if ($asset === null) {
            return;
        }

        $treeDirty = false;
        if ($riga['albero'] !== null && $asset->tree) {
            // Solo i valori davvero letti dal file: le chiavi rimaste a null
            // non devono cancellare le misure già in scheda
            $asset->tree->fill(array_filter($riga['albero'], fn ($v) => $v !== null));
            $treeDirty = $asset->tree->isDirty();
        }

        $asset->geom = $riga['ewkb'];
        $asset->survey_method = 'shapefile_import';
        if ($riga['data_rilievo'] !== null) {
            $asset->surveyed_at = $riga['data_rilievo'];
        }
        if ($riga['note'] !== null) {
            $asset->notes = $riga['note'];
        }
        if ($riga['attributi'] !== []) {
            $asset->attributes = [...($asset->attributes ?? []), ...$riga['attributi']];
        }
        $asset->updated_by = Auth::id();
        $asset->save();

        // Modifiche alla sola scheda albero: la versione va avanzata a mano,
        // come fa la scheda (la riga assets non cambia e il lock ottimistico
        // non vedrebbe il salvataggio)
        if ($treeDirty && ! $asset->wasChanged()) {
            DB::update('UPDATE assets SET version = version + 1, updated_at = now(), updated_by = ? WHERE id = ?', [
                Auth::id(), $asset->id,
            ]);
        }
        if ($treeDirty) {
            $asset->tree->save();
        }
    }

    /** MultiPoint/MultiLineString/MultiPolygon a parte singola -> geometria semplice. */
    private function semplifica(array $geometry): array
    {
        $tipo = $geometry['type'] ?? '';
        if (str_starts_with($tipo, 'Multi')
            && is_array($geometry['coordinates'] ?? null)
            && count($geometry['coordinates']) === 1) {
            return ['type' => substr($tipo, 5), 'coordinates' => $geometry['coordinates'][0]];
        }

        return $geometry;
    }

    private function validaAlbero(array $albero, int $index, string $label, array &$errors): array|false
    {
        $validator = Validator::make($albero, [
            'plant_number' => ['nullable', 'string', 'max:20'],
            'genus' => ['nullable', 'string', 'max:100'],
            'species' => ['nullable', 'string', 'max:150'],
            'cultivar' => ['nullable', 'string', 'max:150'],
            'height_m' => ['nullable', 'numeric', 'min:0', 'max:150'],
            'dbh_cm' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'crown_diameter_m' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        if ($validator->fails()) {
            $errors[] = ['index' => $index, 'error' => "{$label}: ".$validator->errors()->first()];

            return false;
        }

        return $albero;
    }

    public function validaMappatura(array $mappatura): void
    {
        $viste = [];
        foreach ($mappatura as $colonna => $destinazione) {
            if (! isset(self::DESTINAZIONI[$destinazione])) {
                throw ValidationException::withMessages([
                    'mappatura' => "Destinazione '{$destinazione}' non riconosciuta per la colonna '{$colonna}'.",
                ]);
            }
            if (isset($viste[$destinazione])) {
                throw ValidationException::withMessages([
                    'mappatura' => 'La destinazione "'.self::DESTINAZIONI[$destinazione]['label']."\" è assegnata a due colonne ('{$viste[$destinazione]}' e '{$colonna}'): scegline una.",
                ]);
            }
            $viste[$destinazione] = $colonna;
        }
    }

    // ---- Conservazione dell'analisi ---------------------------------------

    private function cartella(): string
    {
        $dir = storage_path('app/import-tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir;
    }

    private function conserva(array $geojson, string $tenantId): string
    {
        $this->puliziaScaduti();
        $token = bin2hex(random_bytes(20));
        file_put_contents($this->cartella()."/{$tenantId}-{$token}.json", json_encode($geojson));

        return $token;
    }

    /** Riprende il file conservato dall'analisi; 422 se scaduto o estraneo. */
    public function riprendi(string $token, string $tenantId): array
    {
        // Il gettone entra in un nome di file: solo esadecimale, niente percorsi
        if (! preg_match('/^[0-9a-f]{40}$/', $token)) {
            throw ValidationException::withMessages(['file_token' => 'Analisi non valida: ricarica il file.']);
        }
        $path = $this->cartella()."/{$tenantId}-{$token}.json";
        if (! is_file($path)) {
            throw ValidationException::withMessages([
                'file_token' => 'Analisi scaduta o non trovata: ricarica il file e ripeti l\'analisi.',
            ]);
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['file_token' => 'Analisi non leggibile: ricarica il file.']);
        }

        return $decoded;
    }

    /** Butta via l'analisi consumata: dopo l'import vero il gettone non deve
     * restare buono, o un secondo clic duplicherebbe gli elementi. */
    public function dimentica(string $token, string $tenantId): void
    {
        if (preg_match('/^[0-9a-f]{40}$/', $token)) {
            @unlink($this->cartella()."/{$tenantId}-{$token}.json");
        }
    }

    private function puliziaScaduti(): void
    {
        foreach (glob($this->cartella().'/*.json') ?: [] as $path) {
            if (filemtime($path) < time() - self::SCADENZA_SECONDI) {
                @unlink($path);
            }
        }
    }
}
