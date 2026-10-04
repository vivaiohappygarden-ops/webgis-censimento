<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Export\CamDeliveryBuilder;
use App\Services\Export\CamExporter;
use App\Services\Export\ElencoPdf;
use App\Services\Export\FoglioXlsx;
use App\Services\Export\MappaPdf;
use App\Support\AssetStatus;
use App\Support\Audit;
use App\Support\FiltriElementi;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExportController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:assets.view')];
    }

    /** Pacchetto di consegna completo: tutti i layer, foto e manifest. */
    public function camDelivery(Request $request, CamDeliveryBuilder $builder)
    {
        $data = $request->validate([
            'format' => ['sometimes', 'in:geojson,shapefile'],
        ]);
        $format = $data['format'] ?? 'shapefile';
        $srid = Organization::find($request->user()->tenant_id)?->metric_srid ?? 7791;

        // Riferimento della consegna: il codice ISTAT quando il territorio è
        // di un solo comune, altrimenti l'identificativo dell'organizzazione
        $istat = Site::query()->whereNotNull('istat_code')->distinct()->pluck('istat_code');
        $tag = $istat->count() === 1
            ? $istat->first()
            : (Organization::find($request->user()->tenant_id)?->slug ?? 'consegna');

        $zipPath = $builder->build($srid, $format, $tag);

        Audit::log('export.cam_delivery', null, ['format' => $format, 'riferimento' => $tag]);

        return response()->download($zipPath, "consegna_cam_{$tag}_".now()->format('Ymd').'.zip', [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    public function cam(Request $request, CamExporter $exporter)
    {
        $data = $request->validate([
            'layer' => ['required', Rule::in(CamExporter::LAYERS)],
            'format' => ['sometimes', 'in:geojson,shapefile'],
        ]);

        $layer = $data['layer'];
        $format = $data['format'] ?? 'geojson';
        $srid = Organization::find($request->user()->tenant_id)?->metric_srid ?? 7791;

        $collection = $exporter->featureCollection($layer, $srid);

        Audit::log('export.cam', null, [
            'layer' => $layer,
            'format' => $format,
            'features' => count($collection['features']),
        ]);

        if ($format === 'shapefile') {
            $zipPath = $exporter->toShapefileZip($collection, $layer, $srid);

            return response()->download($zipPath, "cam_{$layer}_".now()->format('Ymd').'.zip', [
                'Content-Type' => 'application/zip',
            ])->deleteFileAfterSend(true);
        }

        return response()->streamDownload(
            fn () => print (json_encode($collection, JSON_UNESCAPED_UNICODE)),
            "cam_{$layer}_".now()->format('Ymd').'.geojson',
            ['Content-Type' => 'application/geo+json'],
        );
    }

    /**
     * Elenco del censimento in CSV per Excel: punto e virgola, BOM UTF-8 e
     * righe in streaming (mai tutto in memoria). Rispetta gli stessi filtri
     * della pagina Censimento.
     */
    /** L'indirizzo con estensione .xlsx: stesso metodo, formato imposto. */
    public function assetsXlsxRoute(Request $request)
    {
        return $this->assetsCsv($request->merge(['formato' => 'xlsx']));
    }

    /**
     * Lo stesso elenco filtrato, in PDF da stampare o allegare: intestazione
     * dell'organizzazione, filtri applicati scritti in chiaro, le colonne che
     * stanno su un foglio (quelle segnate per il PDF in colonneAssets, con i
     * valori di rigaAsset: nessuna seconda lista), nell'ordine dell'elenco
     * (cartellino, poi data di rilievo). Il documento lo scrive ElencoPdf senza
     * dompdf; il tetto di righe protegge il server e il PDF lo dichiara.
     */
    public function assetsPdf(Request $request, ElencoPdf $elenco)
    {
        $query = FiltriElementi::applica($request, Asset::query());
        $totale = (clone $query)->count();
        $tetto = max(1, (int) config('esportazioni.pdf_righe_massime', 20000));
        $ids = (clone $query)->orderByRaw('assets.census_code ASC NULLS LAST')->orderBy('assets.created_at')->orderBy('assets.id')
            ->limit($tetto)->pluck('assets.id')->all();
        $indici = array_keys(array_filter($this->colonneAssets(), fn ($c) => $c['pdf'] ?? false));
        $colonne = array_values(array_filter($this->colonneAssets(), fn ($c) => $c['pdf'] ?? false));

        // Le righe si leggono a blocchi nell'ordine deciso sopra (chunkById ordinerebbe per id)
        $posizione = array_flip($ids);
        $righe = array_fill(0, count($ids), null);
        foreach (array_chunk($ids, 500) as $blocco) {
            foreach ($this->queryEsportazione()->whereIn('assets.id', $blocco)->get() as $asset) {
                $tutti = $this->rigaAsset($asset);
                $righe[$posizione[$asset->id]] = array_map(fn ($i) => $tutti[$i], $indici);
            }
        }
        $righe = array_values(array_filter($righe, fn ($r) => $r !== null));

        // Un solo orologio per tutto il documento, come per le altre stampe
        $adesso = now('Europe/Rome');
        $pdf = $elenco->componi([
            'organization' => Organization::query()->find($request->user()->tenant_id),
            'colonne' => $colonne,
            'righe' => $righe,
            'totale' => $totale,
            'tetto' => $tetto,
            'filtri' => $this->filtriInChiaro($request),
            'stampatoIl' => $adesso,
        ]);

        Audit::log('export.assets_pdf', null, ['filters' => $request->only(FiltriElementi::PARAMETRI), 'righe' => count($righe), 'totale' => $totale]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="elenco_elementi_'.$adesso->format('Ymd').'.pdf"',
        ]);
    }

    /**
     * La mappa in PDF: l'immagine disegnata dal browser (lo sfondo arriva da
     * server esterni che il server non interroga) con intestazione, data, scala
     * a video e grafica, nord, coordinate del centro, legenda e attribuzione.
     */
    public function mappaPdf(Request $request, MappaPdf $mappa)
    {
        $data = $request->validate([
            'immagine' => ['required', 'string', 'max:16000000'],
            'titolo' => ['nullable', 'string', 'max:160'],
            'sottotitolo' => ['nullable', 'string', 'max:300'],
            'scala' => ['nullable', 'string', 'max:40'],
            'metri_larghezza' => ['nullable', 'numeric', 'min:0.1', 'max:100000000'],
            'rotazione' => ['nullable', 'numeric', 'between:-360,360'],
            'centro' => ['nullable', 'array'],
            'centro.wgs84' => ['nullable', 'string', 'max:80'],
            'centro.metrico' => ['nullable', 'string', 'max:80'],
            'centro.sistema' => ['nullable', 'string', 'max:40'],
            'legenda' => ['nullable', 'array', 'max:40'],
            'legenda.*.etichetta' => ['required', 'string', 'max:80'],
            'legenda.*.colore' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'legenda.*.forma' => ['required', Rule::in(MappaPdf::FORME)],
            'attribuzione' => ['nullable', 'string', 'max:300'],
        ]);
        $immagine = $mappa->immagineDa($data['immagine']);
        if (! $immagine) {
            throw ValidationException::withMessages(['immagine' => "L'immagine della mappa non è leggibile o è troppo grande: riprova la stampa."]);
        }

        $adesso = now('Europe/Rome');
        $titolo = trim((string) ($data['titolo'] ?? '')) ?: MappaPdf::TITOLO;
        $pdf = $mappa->componi([
            'organization' => Organization::query()->find($request->user()->tenant_id),
            'immagine' => $immagine,
            'titolo' => $titolo,
            'sottotitolo' => $data['sottotitolo'] ?? null,
            'scala' => $data['scala'] ?? null,
            'metri_larghezza' => isset($data['metri_larghezza']) ? (float) $data['metri_larghezza'] : null,
            'rotazione' => (float) ($data['rotazione'] ?? 0),
            'centro' => $data['centro'] ?? [],
            'legenda' => array_values($data['legenda'] ?? []),
            'attribuzione' => $data['attribuzione'] ?? null,
            'stampatoIl' => $adesso,
        ]);

        Audit::log('export.mappa_pdf', null, ['titolo' => $titolo, 'sottotitolo' => $data['sottotitolo'] ?? null, 'scala' => $data['scala'] ?? null]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="mappa_'.$adesso->format('Ymd_Hi').'.pdf"',
        ]);
    }

    /** I filtri dell'elenco scritti come li legge una persona, per la testata del PDF. */
    private function filtriInChiaro(Request $request): array
    {
        $voci = [];
        if ($request->filled('client_id')) {
            $nome = \App\Models\Client::query()->whereKey($request->string('client_id'))->value('name');
            $voci[] = 'committente '.($nome ?? '?');
        }
        if ($request->filled('locality_id')) {
            $nome = \App\Models\Locality::query()->whereKey($request->string('locality_id'))->value('name');
            $voci[] = 'localita\' '.($nome ?? '?');
        }
        if ($request->filled('area_id')) {
            $nome = \App\Models\Area::query()->whereKey($request->string('area_id'))->value('name');
            $voci[] = 'area '.($nome ?? '?');
        }
        if ($request->filled('object_type_id')) {
            $tipo = \App\Models\CatalogObjectType::query()->whereKey($request->string('object_type_id'))->first(['code', 'name']);
            $voci[] = 'tipo '.($tipo ? "{$tipo->code} {$tipo->name}" : '?');
        } elseif ($request->filled('type_code')) {
            $voci[] = 'tipo '.$request->string('type_code');
        }
        if ($request->filled('status')) {
            $voci[] = 'stato '.(AssetStatus::LABELS[$request->string('status')->toString()] ?? $request->string('status'));
        } elseif ($request->has('archivio')) {
            $voci[] = $request->boolean('archivio') ? 'solo archivio (abbattuti e dismessi)' : 'senza archivio';
        } elseif ($request->boolean('hide_removed')) {
            $voci[] = 'senza abbattuti';
        }
        if ($request->filled('q')) {
            $voci[] = 'ricerca "'.$request->string('q').'"';
        }
        if ($request->filled('vta')) {
            $voci[] = ['scaduta' => 'VTA scaduta', 'in_scadenza' => 'VTA in scadenza', 'mai' => 'mai valutati', 'valutato' => 'con VTA'][$request->string('vta')->toString()] ?? 'VTA '.$request->string('vta');
        }
        if ($request->boolean('senza_specie')) {
            $voci[] = 'senza specie';
        }

        return $voci;
    }

    /** Le relazioni che servono a rigaAsset: una volta sola per CSV, foglio e PDF. */
    private function queryEsportazione()
    {
        return Asset::query()->with([
            'objectType:id,code,name,sub_type_id',
            'objectType.subType:id,name,main_type_id',
            'objectType.subType.mainType:id,name',
            'area:id,name,locality_id',
            'area.locality:id,name,site_id',
            'area.locality.site:id,name,client_id',
            'area.locality.site.client:id,name',
            'tree:asset_id,genus,species,common_name,height_m,dbh_cm',
        ]);
    }

    public function assetsCsv(Request $request)
    {
        // Stessa scelta fatta a video: il file esporta quello che si sta
        // guardando. I filtri sono quelli dell'elenco (FiltriElementi), non
        // una copia: un filtro aggiunto all'elenco vale subito anche qui
        $query = FiltriElementi::applica($request, $this->queryEsportazione());

        $formato = $request->string('formato')->lower()->toString() ?: 'csv';

        Audit::log('export.assets_'.($formato === 'xlsx' ? 'xlsx' : 'csv'), null, ['filters' => $request->only(FiltriElementi::PARAMETRI)]);

        return $formato === 'xlsx'
            ? $this->assetsXlsx($query)
            : $this->assetsCsvStream($query);
    }

    /**
     * Le colonne dell'esportazione del censimento: titolo, larghezza in
     * Excel e tipo del dato.
     *
     * Stanno qui una volta sola perche' il CSV, il foglio Excel e il PDF
     * devono esportare esattamente le stesse cose, nello stesso ordine: due
     * elenchi paralleli divergerebbero al primo campo aggiunto. Il PDF prende
     * le sole colonne segnate "pdf" (quelle che stanno su un foglio A4
     * orizzontale), con i valori della stessa riga.
     *
     * @return list<array{titolo: string, larghezza: int, tipo: string, pdf?: bool, decimali?: int}>
     */
    private function colonneAssets(): array
    {
        return [
            ['titolo' => 'Codice', 'larghezza' => 14, 'tipo' => 'testo', 'pdf' => true],
            ['titolo' => 'Tipo', 'larghezza' => 10, 'tipo' => 'testo', 'pdf' => true],
            ['titolo' => 'Descrizione tipo', 'larghezza' => 28, 'tipo' => 'testo', 'pdf' => true],
            ['titolo' => 'Categoria', 'larghezza' => 18, 'tipo' => 'testo'],
            ['titolo' => 'Committente', 'larghezza' => 24, 'tipo' => 'testo', 'pdf' => true],
            ['titolo' => 'Area', 'larghezza' => 22, 'tipo' => 'testo', 'pdf' => true],
            ['titolo' => 'Localita', 'larghezza' => 22, 'tipo' => 'testo', 'pdf' => true],
            ['titolo' => 'Stato', 'larghezza' => 14, 'tipo' => 'testo', 'pdf' => true],
            ['titolo' => 'Data rilievo', 'larghezza' => 12, 'tipo' => 'data', 'pdf' => true],
            ['titolo' => 'Specie', 'larghezza' => 22, 'tipo' => 'testo', 'pdf' => true],
            ['titolo' => 'Nome comune', 'larghezza' => 20, 'tipo' => 'testo'],
            ['titolo' => 'Altezza (m)', 'larghezza' => 11, 'tipo' => 'numero', 'pdf' => true, 'decimali' => 1],
            ['titolo' => 'Diametro fusto (cm)', 'larghezza' => 16, 'tipo' => 'numero', 'pdf' => true, 'decimali' => 1],
            ['titolo' => 'Superficie (m2)', 'larghezza' => 14, 'tipo' => 'numero', 'pdf' => true, 'decimali' => 0],
            ['titolo' => 'Lunghezza (m)', 'larghezza' => 13, 'tipo' => 'numero', 'pdf' => true, 'decimali' => 1],
            ['titolo' => 'Perimetro (m)', 'larghezza' => 13, 'tipo' => 'numero'],
            ['titolo' => 'Note', 'larghezza' => 40, 'tipo' => 'testo'],
            ['titolo' => 'Data abbattimento/rimozione', 'larghezza' => 16, 'tipo' => 'data'],
            ['titolo' => 'Motivo abbattimento/rimozione', 'larghezza' => 30, 'tipo' => 'testo'],
        ];
    }

    /**
     * Una riga dell'esportazione, con i valori grezzi (date come date,
     * numeri come numeri): a formattarli ci pensa chi scrive il file, che sa
     * se sta facendo un CSV o un foglio Excel.
     *
     * @return list<mixed>
     */
    private function rigaAsset(Asset $asset): array
    {
        return [
            $asset->census_code,
            $asset->objectType?->code,
            $asset->objectType?->name,
            $asset->objectType?->subType?->mainType?->name,
            $asset->area?->locality?->site?->client?->name,
            $asset->area?->name,
            $asset->area?->locality?->name,
            AssetStatus::LABELS[$asset->status] ?? $asset->status,
            $asset->surveyed_at,
            // Il campo specie contiene gia' il binomio completo
            $asset->tree?->species ?: ($asset->tree?->genus ?? null),
            $asset->tree?->common_name,
            $asset->tree?->height_m,
            $asset->tree?->dbh_cm,
            $asset->computed_area_sqm,
            $asset->computed_length_m,
            $asset->computed_perimeter_m,
            $asset->notes,
            $asset->status === 'removed' ? $asset->valid_to : null,
            $asset->status === 'removed' ? $asset->removal_reason : null,
        ];
    }

    /** Il CSV di sempre: separatore punto e virgola, virgola decimale, BOM. */
    private function assetsCsvStream($query)
    {
        $colonne = $this->colonneAssets();
        // I decimali con la virgola, come li aspetta l'Excel italiano
        $num = fn ($value) => $value === null ? '' : str_replace('.', ',', (string) (float) $value);
        // Testo libero neutralizzato: una cella che inizia con = + - @ ecc.
        // verrebbe eseguita da Excel come formula (iniezione CSV). Nel foglio
        // .xlsx non serve: li' una cella di testo resta testo
        $text = fn (?string $value) => $value !== null && preg_match('/^[=+\-@\t\r]/', $value)
            ? "'".$value
            : ($value ?? '');

        $formatta = function (array $riga) use ($colonne, $num, $text) {
            foreach ($riga as $i => $valore) {
                $riga[$i] = match ($colonne[$i]['tipo']) {
                    'numero' => $num($valore),
                    'data' => $valore?->format('d/m/Y') ?? '',
                    default => $text($valore === null ? null : (string) $valore),
                };
            }

            return $riga;
        };

        return response()->streamDownload(function () use ($query, $colonne, $formatta) {
            $out = fopen('php://output', 'w');
            // BOM: senza, l'Excel italiano legge le lettere accentate sbagliate
            fwrite($out, "\xEF\xBB\xBF");
            // escape '': niente backslash "magici" (RFC 4180) e niente
            // avviso di deprecazione a ogni riga su PHP 8.4
            fputcsv($out, array_column($colonne, 'titolo'), ';', '"', '');

            // Solo chunkById, nessun altro orderBy: un ordinamento diverso
            // dalla colonna cursore romperebbe l'invariante dei blocchi
            // (righe saltate o duplicate oltre le prime 500)
            $query->chunkById(500, function ($assets) use ($out, $formatta) {
                foreach ($assets as $asset) {
                    fputcsv($out, $formatta($this->rigaAsset($asset)), ';', '"', '');
                }
            });
            fclose($out);
        }, 'censimento_'.now()->format('Ymd').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** Il foglio Excel vero: intestazione bloccata, filtri, tipi giusti. */
    private function assetsXlsx($query)
    {
        $foglio = new FoglioXlsx('Censimento');
        $foglio->intestazione($this->colonneAssets());

        $query->chunkById(500, function ($assets) use ($foglio) {
            foreach ($assets as $asset) {
                $foglio->riga($this->rigaAsset($asset));
            }
        });

        $percorso = $foglio->scrivi();

        // deleteFileAfterSend: il file temporaneo non deve restare sul server
        return response()->download(
            $percorso,
            'censimento_'.now()->format('Ymd').'.xlsx',
            ['Content-Type' => FoglioXlsx::mime()],
        )->deleteFileAfterSend(true);
    }
}
