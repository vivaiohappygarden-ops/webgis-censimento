<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAreaRequest;
use App\Http\Requests\UpdateAreaRequest;
use App\Models\Area;
use App\Models\Locality;
use App\Support\Audit;
use App\Support\Geometry;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AreaController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:areas.view', only: ['index', 'show']),
            new Middleware('can:areas.create', only: ['store']),
            new Middleware('can:areas.update', only: ['update']),
            new Middleware('can:areas.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        ListQuery::validateUuidFilters($request, ['locality_id']);

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
            $query->whereRaw('geom && ST_MakeEnvelope(?, ?, ?, ?, 4326)', ListQuery::bbox($request));
        }

        return response()->json(
            $query->orderBy('name')->paginate(ListQuery::perPage($request))
        );
    }

    public function store(StoreAreaRequest $request): JsonResponse
    {
        $data = $request->validated();
        Locality::findOrFail($data['locality_id']);

        if (! empty($data['parent_id'])) {
            // findOrFail tenant-scoped: un parent di un altro tenant risulta inesistente
            Area::findOrFail($data['parent_id']);
        }

        $area = new Area([
            ...collect($data)->except('geometry')->all(),
            'geom' => Geometry::toEwkb($data['geometry'], forceMultiPolygon: true),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
        $this->assertValidityDates($area);
        $area->save();

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
        if (! empty($data['parent_id'])) {
            if ($data['parent_id'] === $area->id) {
                throw ValidationException::withMessages(['parent_id' => "Un'area non può essere sottoarea di sé stessa."]);
            }
            Area::findOrFail($data['parent_id']);
        }

        $area->fill($data);
        $area->updated_by = $request->user()->id;
        $this->assertValidityDates($area);
        $area->save();

        Audit::log('area.updated', $area);

        return $this->show($area->id);
    }

    public function destroy(string $id): Response
    {
        DB::transaction(function () use ($id) {
            $area = Area::query()->lockForUpdate()->findOrFail($id);

            $assetCount = $area->assets()->count();
            if ($assetCount > 0) {
                throw ValidationException::withMessages([
                    'area' => "L'area contiene {$assetCount} elementi censiti: spostali o eliminali prima di eliminarla.",
                ]);
            }

            $area->delete();
            Audit::log('area.deleted', $area);
        });

        return response()->noContent();
    }

    private function assertValidityDates(Area $area): void
    {
        $from = $area->valid_from ?? now()->startOfDay();

        if ($area->valid_to && $area->valid_to->lt($from)) {
            throw ValidationException::withMessages([
                'valid_to' => 'La data di fine validità non può precedere quella di inizio ('.$from->toDateString().').',
            ]);
        }
    }
}
