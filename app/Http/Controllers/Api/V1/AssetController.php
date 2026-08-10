<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\UpdateAssetRequest;
use App\Models\Area;
use App\Models\Asset;
use App\Models\CatalogObjectType;
use App\Support\Audit;
use App\Support\Geometry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class AssetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Asset::query()
            ->with('objectType:id,code,name,allowed_geometry')
            ->selectRaw('assets.*, ST_AsGeoJSON(geom)::json AS geom_geojson')
            ->withCasts(['geom_geojson' => 'array']);

        if ($request->filled('area_id')) {
            $query->where('area_id', $request->string('area_id'));
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
        if ($request->filled('q')) {
            $q = '%'.$request->string('q').'%';
            $query->where(fn ($w) => $w->where('census_code', 'ilike', $q)->orWhere('notes', 'ilike', $q));
        }
        if ($request->filled('bbox')) {
            $parts = array_map('floatval', explode(',', (string) $request->input('bbox')));
            abort_if(count($parts) !== 4, 422, 'bbox deve essere minLon,minLat,maxLon,maxLat');
            $query->whereRaw('geom && ST_MakeEnvelope(?, ?, ?, ?, 4326)', $parts);
        }

        return response()->json(
            $query->orderByDesc('created_at')->paginate(min((int) $request->input('per_page', 50), 200))
        );
    }

    public function store(StoreAssetRequest $request): JsonResponse
    {
        $data = $request->validated();

        $type = CatalogObjectType::findOrFail($data['object_type_id']);
        Area::findOrFail($data['area_id']);
        $this->assertGeometryMatchesType($data['geometry'], $type);

        $asset = Asset::create([
            ...collect($data)->except('geometry')->all(),
            'geom' => Geometry::toEwkb($data['geometry']),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        Audit::log('asset.created', $asset, ['type' => $type->code]);

        return response()->json(['data' => $this->show($asset->id)->getData()->data], 201);
    }

    public function show(string $id): JsonResponse
    {
        $asset = Asset::query()
            ->with([
                'objectType:id,code,name,allowed_geometry',
                'area:id,name,code',
                'tags' => fn ($q) => $q->whereIn('status', ['active', 'unassigned']),
            ])
            ->withCount(['photos', 'documents', 'versions'])
            ->selectRaw('assets.*, ST_AsGeoJSON(geom)::json AS geom_geojson')
            ->withCasts(['geom_geojson' => 'array'])
            ->findOrFail($id);

        return response()->json(['data' => $asset]);
    }

    public function update(UpdateAssetRequest $request, string $id): JsonResponse
    {
        $asset = Asset::findOrFail($id);
        $data = $request->validated();

        // Optimistic locking opzionale: il client dichiara la versione che sta modificando
        if (isset($data['version']) && (int) $data['version'] !== $asset->version) {
            abort(409, "Conflitto di versione: la scheda è stata modificata da altri (versione attuale {$asset->version}).");
        }
        unset($data['version']);

        $type = isset($data['object_type_id'])
            ? CatalogObjectType::findOrFail($data['object_type_id'])
            : $asset->objectType;

        if (array_key_exists('geometry', $data)) {
            $this->assertGeometryMatchesType($data['geometry'], $type);
            $data['geom'] = Geometry::toEwkb($data['geometry']);
            unset($data['geometry']);
        }
        if (array_key_exists('area_id', $data)) {
            Area::findOrFail($data['area_id']);
        }

        $asset->fill($data);
        $asset->updated_by = $request->user()->id;
        $asset->save();

        Audit::log('asset.updated', $asset);

        return $this->show($asset->id);
    }

    public function destroy(string $id): Response
    {
        $asset = Asset::findOrFail($id);
        $asset->delete();

        Audit::log('asset.deleted', $asset);

        return response()->noContent();
    }

    private function assertGeometryMatchesType(array $geometry, CatalogObjectType $type): void
    {
        if (! in_array($geometry['type'], $type->allowedGeometryTypes(), true)) {
            throw ValidationException::withMessages([
                'geometry' => "Il tipo oggetto {$type->code} richiede una geometria ".
                    implode('/', $type->allowedGeometryTypes()).", ricevuta {$geometry['type']}.",
            ]);
        }
    }
}
