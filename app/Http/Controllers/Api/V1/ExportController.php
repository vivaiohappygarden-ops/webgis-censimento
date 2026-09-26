<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Export\CamDeliveryBuilder;
use App\Services\Export\CamExporter;
use App\Services\Export\FoglioXlsx;
use App\Support\AssetStatus;
use App\Support\Audit;
use App\Support\FiltriElementi;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

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

    public function assetsCsv(Request $request)
    {
        // Stessa scelta fatta a video: il file esporta quello che si sta
        // guardando. I filtri sono quelli dell'elenco (FiltriElementi), non
        // una copia: un filtro aggiunto all'elenco vale subito anche qui
        $query = FiltriElementi::applica($request, Asset::query()
            ->with([
                'objectType:id,code,name,sub_type_id',
                'objectType.subType:id,name,main_type_id',
                'objectType.subType.mainType:id,name',
                'area:id,name,locality_id',
                'area.locality:id,name,site_id',
                'area.locality.site:id,name,client_id',
                'area.locality.site.client:id,name',
                'tree:asset_id,genus,species,common_name,height_m,dbh_cm',
            ]));

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
     * Stanno qui una volta sola perche' il CSV e il foglio Excel devono
     * esportare esattamente le stesse cose, nello stesso ordine: due elenchi
     * paralleli divergerebbero al primo campo aggiunto.
     *
     * @return list<array{titolo: string, larghezza: int, tipo: string}>
     */
    private function colonneAssets(): array
    {
        return [
            ['titolo' => 'Codice', 'larghezza' => 14, 'tipo' => 'testo'],
            ['titolo' => 'Tipo', 'larghezza' => 10, 'tipo' => 'testo'],
            ['titolo' => 'Descrizione tipo', 'larghezza' => 28, 'tipo' => 'testo'],
            ['titolo' => 'Categoria', 'larghezza' => 18, 'tipo' => 'testo'],
            ['titolo' => 'Committente', 'larghezza' => 24, 'tipo' => 'testo'],
            ['titolo' => 'Area', 'larghezza' => 22, 'tipo' => 'testo'],
            ['titolo' => 'Localita', 'larghezza' => 22, 'tipo' => 'testo'],
            ['titolo' => 'Stato', 'larghezza' => 14, 'tipo' => 'testo'],
            ['titolo' => 'Data rilievo', 'larghezza' => 12, 'tipo' => 'data'],
            ['titolo' => 'Specie', 'larghezza' => 22, 'tipo' => 'testo'],
            ['titolo' => 'Nome comune', 'larghezza' => 20, 'tipo' => 'testo'],
            ['titolo' => 'Altezza (m)', 'larghezza' => 11, 'tipo' => 'numero'],
            ['titolo' => 'Diametro fusto (cm)', 'larghezza' => 16, 'tipo' => 'numero'],
            ['titolo' => 'Superficie (m2)', 'larghezza' => 14, 'tipo' => 'numero'],
            ['titolo' => 'Lunghezza (m)', 'larghezza' => 13, 'tipo' => 'numero'],
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
