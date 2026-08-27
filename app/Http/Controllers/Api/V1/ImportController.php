<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\CatalogObjectType;
use App\Services\Import\GeoJsonImporter;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

class ImportController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:assets.create')];
    }

    public function geojson(Request $request, GeoJsonImporter $importer): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'area_id' => ['required', 'uuid'],
            'default_object_type_id' => ['nullable', 'uuid'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        $area = Area::findOrFail($data['area_id']);
        $defaultType = ! empty($data['default_object_type_id'])
            ? CatalogObjectType::findOrFail($data['default_object_type_id'])
            : null;

        $geojson = json_decode($data['file']->get(), true);
        if (! is_array($geojson)) {
            throw ValidationException::withMessages(['file' => 'Il file non è un JSON valido.']);
        }

        $dryRun = (bool) ($data['dry_run'] ?? true);
        $report = $importer->run($geojson, $area, $defaultType, $dryRun);

        if (! $dryRun) {
            Audit::log('import.geojson', $area, [
                'imported' => $report['imported'],
                'errors' => $report['errors_total'],
            ]);
        }

        return response()->json(['data' => $report]);
    }

    /**
     * Primo passo dell'import generico: si carica il file (shapefile zip,
     * GeoJSON, GeoPackage o KML) e si riceve l'elenco delle colonne con gli
     * esempi, l'anteprima delle prime righe e una proposta di mappatura.
     * Il file convertito resta conservato un'ora: la verifica e l'import
     * usano il gettone, senza ricaricare nulla.
     */
    public function analizza(Request $request, \App\Services\Import\ImportGenerico $importer): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:51200'],
        ]);

        $geojson = (new \App\Services\Import\ConvertitoreGeo)->aGeoJson($data['file']);

        return response()->json(['data' => $importer->analizza($geojson, $request->user()->tenant_id)]);
    }

    /** Secondo passo: verifica (dry-run) o import vero con la mappatura scelta. */
    public function generico(Request $request, \App\Services\Import\ImportGenerico $importer): JsonResponse
    {
        $data = $request->validate([
            'file_token' => ['required', 'string', 'max:64'],
            'area_id' => ['required', 'uuid'],
            'mappatura' => ['present', 'array', 'max:100'],
            'mappatura.*' => ['string', 'max:40'],
            'default_object_type_id' => ['nullable', 'uuid'],
            'esistenti' => ['sometimes', \Illuminate\Validation\Rule::in(['salta', 'aggiorna'])],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        $esistenti = $data['esistenti'] ?? 'salta';
        // Aggiornare schede esistenti è una modifica, non una creazione:
        // serve anche il permesso di modifica del censimento
        if ($esistenti === 'aggiorna' && ! $request->user()->can('assets.update')) {
            abort(403, 'Per aggiornare elementi esistenti serve il permesso di modifica del censimento.');
        }

        $area = Area::findOrFail($data['area_id']);
        $defaultType = ! empty($data['default_object_type_id'])
            ? CatalogObjectType::findOrFail($data['default_object_type_id'])
            : null;
        $geojson = $importer->riprendi($data['file_token'], $request->user()->tenant_id);
        $dryRun = (bool) ($data['dry_run'] ?? true);

        $report = $importer->importa($geojson, $area, $data['mappatura'], $defaultType, $esistenti, $dryRun);

        if (! $dryRun) {
            Audit::log('import.generico', $area, [
                'imported' => $report['imported'],
                'updated' => $report['updated'],
                'errors' => $report['errors_total'],
            ]);
        }

        return response()->json(['data' => $report]);
    }

    /** Le mappature salvate del tenant, per riusarle sui tracciati ricorrenti. */
    public function mappature(): JsonResponse
    {
        return response()->json([
            'data' => \App\Models\ImportMapping::query()->orderBy('name')->get(),
        ]);
    }

    /** Salva (o aggiorna, a parità di nome) una mappatura con un nome. */
    public function salvaMappatura(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'mapping' => ['present', 'array', 'max:100'],
            'mapping.*' => ['string', 'max:40'],
            'default_object_type_id' => ['nullable', 'uuid'],
        ]);
        if (! empty($data['default_object_type_id'])) {
            CatalogObjectType::findOrFail($data['default_object_type_id']);
        }

        $mappatura = \App\Models\ImportMapping::query()->updateOrCreate(
            ['name' => trim($data['name'])],
            [
                'tenant_id' => $request->user()->tenant_id,
                'mapping' => $data['mapping'],
                'default_object_type_id' => $data['default_object_type_id'] ?? null,
                'created_by' => $request->user()->id,
            ],
        );

        return response()->json(['data' => $mappatura], 201);
    }

    public function eliminaMappatura(string $id): \Illuminate\Http\Response
    {
        \App\Models\ImportMapping::query()->findOrFail($id)->delete();

        return response()->noContent();
    }

    /** Import nel formato Modello Dati v2.1: shapefile zip (RDN2008) o GeoJSON CAM. */
    public function cam(Request $request, \App\Services\Import\CamImporter $importer): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:51200'],
            'area_id' => ['required', 'uuid'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        $area = Area::findOrFail($data['area_id']);
        $geojson = $importer->toGeoJson($data['file']);
        $dryRun = (bool) ($data['dry_run'] ?? true);

        $report = $importer->run($geojson, $area, $dryRun);

        if (! $dryRun) {
            Audit::log('import.cam', $area, [
                'imported' => $report['imported'],
                'errors' => $report['errors_total'],
            ]);
        }

        return response()->json(['data' => $report]);
    }
}
