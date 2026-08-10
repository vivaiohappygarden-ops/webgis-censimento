<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAreaRequest;
use App\Http\Requests\UpdateAreaRequest;
use App\Models\Area;
use App\Models\Locality;
use App\Support\Audit;
use App\Support\Geometry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class AreaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Area::query()
            ->with('locality:id,name,code')
            ->selectRaw('areas.*, ST_AsGeoJSON(geom)::json AS geom_geojson')
            ->withCasts(['geom_geojson' => 'array']);

        if ($request->filled('locality_id')) {
            $query->where('locality_id', $request->string('locality_id'));
        }
        if ($request->filled('area_type')) {
            $query->where('area_type', $request->string('area_type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('q')) {
            $q = '%'.$request->string('q').'%';
            $query->where(fn ($w) => $w->where('name', 'ilike', $q)->orWhere('code', 'ilike', $q));
        }
        if ($request->filled('bbox')) {
            $query->whereRaw('geom && ST_MakeEnvelope(?, ?, ?, ?, 4326)', $this->parseBbox($request));
        }

        return response()->json(
            $query->orderBy('name')->paginate(min((int) $request->input('per_page', 25), 100))
        );
    }

    public function store(StoreAreaRequest $request): JsonResponse
    {
        $data = $request->validated();
        Locality::findOrFail($data['locality_id']);

        $area = Area::create([
            ...collect($data)->except('geometry')->all(),
            'geom' => Geometry::toEwkb($data['geometry'], forceMultiPolygon: true),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        Audit::log('area.created', $area, ['name' => $area->name]);

        return response()->json(['data' => $this->show($area->id)->getData()->data], 201);
    }

    public function show(string $id): JsonResponse
    {
        $area = Area::query()
            ->with('locality:id,name,code')
            ->withCount('assets')
            ->selectRaw('areas.*, ST_AsGeoJSON(geom)::json AS geom_geojson')
            ->withCasts(['geom_geojson' => 'array'])
            ->findOrFail($id);

        return response()->json(['data' => $area]);
    }

    public function update(UpdateAreaRequest $request, string $id): JsonResponse
    {
        $area = Area::findOrFail($id);
        $data = $request->validated();

        if (array_key_exists('geometry', $data)) {
            $data['geom'] = Geometry::toEwkb($data['geometry'], forceMultiPolygon: true);
            unset($data['geometry']);
        }
        if (array_key_exists('locality_id', $data)) {
            Locality::findOrFail($data['locality_id']);
        }

        $area->fill($data);
        $area->updated_by = $request->user()->id;
        $area->save();

        Audit::log('area.updated', $area);

        return $this->show($area->id);
    }

    public function destroy(string $id): Response
    {
        $area = Area::withCount('assets')->findOrFail($id);

        if ($area->assets_count > 0) {
            throw ValidationException::withMessages([
                'area' => "L'area contiene {$area->assets_count} elementi censiti: spostali o dismettili prima di eliminarla.",
            ]);
        }

        $area->delete();
        Audit::log('area.deleted', $area);

        return response()->noContent();
    }

    private function parseBbox(Request $request): array
    {
        $parts = array_map('floatval', explode(',', (string) $request->input('bbox')));
        abort_if(count($parts) !== 4, 422, 'bbox deve essere minLon,minLat,maxLon,maxLat');

        return $parts;
    }
}
