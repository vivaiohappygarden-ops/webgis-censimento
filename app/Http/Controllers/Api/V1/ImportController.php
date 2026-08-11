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
