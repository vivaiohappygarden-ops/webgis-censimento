<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Export\CamExporter;
use App\Support\Audit;
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
    public function camDelivery(Request $request, \App\Services\Export\CamDeliveryBuilder $builder)
    {
        $data = $request->validate([
            'format' => ['sometimes', 'in:geojson,shapefile'],
        ]);
        $format = $data['format'] ?? 'shapefile';
        $srid = Organization::find($request->user()->tenant_id)?->metric_srid ?? 7791;

        // Riferimento della consegna: il codice ISTAT quando il territorio è
        // di un solo comune, altrimenti l'identificativo dell'organizzazione
        $istat = \App\Models\Site::query()->whereNotNull('istat_code')->distinct()->pluck('istat_code');
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
            fn () => print(json_encode($collection, JSON_UNESCAPED_UNICODE)),
            "cam_{$layer}_".now()->format('Ymd').'.geojson',
            ['Content-Type' => 'application/geo+json'],
        );
    }

    /**
     * Elenco del censimento in CSV per Excel: punto e virgola, BOM UTF-8 e
     * righe in streaming (mai tutto in memoria). Rispetta gli stessi filtri
     * della pagina Censimento.
     */
    public function assetsCsv(Request $request)
    {
        \App\Support\ListQuery::validateUuidFilters($request, ['area_id', 'object_type_id', 'client_id', 'locality_id']);

        $query = \App\Models\Asset::query()
            ->with([
                'objectType:id,code,name,sub_type_id',
                'objectType.subType:id,name,main_type_id',
                'objectType.subType.mainType:id,name',
                'area:id,name,locality_id',
                'area.locality:id,name,site_id',
                'area.locality.site:id,name,client_id',
                'area.locality.site.client:id,name',
                'tree:asset_id,genus,species,common_name,height_m,dbh_cm',
            ]);

        if ($request->filled('area_id')) {
            $query->where('area_id', $request->string('area_id'));
        }
        if ($request->filled('locality_id')) {
            $query->whereHas('area', fn ($w) => $w->where('locality_id', $request->string('locality_id')));
        }
        if ($request->filled('client_id')) {
            $query->whereHas('area.locality.site', fn ($w) => $w->where('client_id', $request->string('client_id')));
        }
        if ($request->filled('object_type_id')) {
            $query->where('object_type_id', $request->string('object_type_id'));
        }
        if ($request->filled('type_code')) {
            $query->whereHas('objectType', fn ($w) => $w->where('code', $request->string('type_code')));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        // Stessa scelta fatta a video: se l'elenco nasconde gli abbattuti,
        // il CSV esporta quello che si sta guardando
        if ($request->boolean('hide_removed')) {
            $query->where('status', '!=', 'removed');
        }
        if ($request->filled('q')) {
            $q = '%'.$request->string('q').'%';
            $query->where(fn ($w) => $w->where('census_code', 'ilike', $q)->orWhere('notes', 'ilike', $q));
        }

        Audit::log('export.assets_csv', null, ['filters' => $request->only([
            'area_id', 'locality_id', 'client_id', 'object_type_id', 'type_code', 'status', 'hide_removed', 'q',
        ])]);

        // Gli stati usati dall'interfaccia del censimento
        $statusLabels = \App\Support\AssetStatus::LABELS;
        // I decimali con la virgola, come li aspetta l'Excel italiano
        $num = fn ($value) => $value === null ? '' : str_replace('.', ',', (string) (float) $value);
        // Testo libero neutralizzato: una cella che inizia con = + - @ ecc.
        // verrebbe eseguita da Excel come formula (iniezione CSV)
        $text = fn (?string $value) => $value !== null && preg_match('/^[=+\-@\t\r]/', $value)
            ? "'".$value
            : ($value ?? '');

        return response()->streamDownload(function () use ($query, $statusLabels, $num, $text) {
            $out = fopen('php://output', 'w');
            // BOM: senza, l'Excel italiano legge le lettere accentate sbagliate
            fwrite($out, "\xEF\xBB\xBF");
            // escape '': niente backslash "magici" (RFC 4180) e niente
            // avviso di deprecazione a ogni riga su PHP 8.4
            fputcsv($out, [
                'Codice', 'Tipo', 'Descrizione tipo', 'Categoria', 'Committente', 'Area', 'Località', 'Stato',
                'Data rilievo', 'Specie', 'Nome comune', 'Altezza (m)', 'Diametro fusto (cm)',
                'Superficie (m2)', 'Lunghezza (m)', 'Perimetro (m)', 'Note',
                'Data abbattimento/rimozione', 'Motivo abbattimento/rimozione',
            ], ';', '"', '');

            // Solo chunkById, nessun altro orderBy: un ordinamento diverso
            // dalla colonna cursore romperebbe l'invariante dei blocchi
            // (righe saltate o duplicate oltre le prime 500)
            $query->chunkById(500, function ($assets) use ($out, $statusLabels, $num, $text) {
                foreach ($assets as $asset) {
                    fputcsv($out, [
                        $text($asset->census_code),
                        $text($asset->objectType?->code),
                        $text($asset->objectType?->name),
                        $text($asset->objectType?->subType?->mainType?->name),
                        $text($asset->area?->locality?->site?->client?->name),
                        $text($asset->area?->name),
                        $text($asset->area?->locality?->name),
                        $statusLabels[$asset->status] ?? $text($asset->status),
                        $asset->surveyed_at?->format('d/m/Y'),
                        // Il campo specie contiene già il binomio completo
                        $text($asset->tree?->species ?: ($asset->tree?->genus ?? '')),
                        $text($asset->tree?->common_name),
                        $num($asset->tree?->height_m),
                        $num($asset->tree?->dbh_cm),
                        $num($asset->computed_area_sqm),
                        $num($asset->computed_length_m),
                        $num($asset->computed_perimeter_m),
                        $text($asset->notes),
                        $asset->status === 'removed' ? $asset->valid_to?->format('d/m/Y') : '',
                        $asset->status === 'removed' ? $text($asset->removal_reason) : '',
                    ], ';', '"', '');
                }
            });
            fclose($out);
        }, 'censimento_'.now()->format('Ymd').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
