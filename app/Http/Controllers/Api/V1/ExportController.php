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
}
