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
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:assets.view', only: ['index', 'show']),
            new Middleware('can:assets.create', only: ['store']),
            new Middleware('can:assets.update', only: ['update']),
            new Middleware('can:assets.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        ListQuery::validateUuidFilters($request, ['area_id', 'object_type_id']);

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
            $query->whereRaw('geom && ST_MakeEnvelope(?, ?, ?, ?, 4326)', ListQuery::bbox($request));
        }

        return response()->json(
            $query->orderByDesc('created_at')->paginate(ListQuery::perPage($request, 50, 200))
        );
    }

    public function store(StoreAssetRequest $request): JsonResponse
    {
        $data = $request->validated();

        $type = CatalogObjectType::findOrFail($data['object_type_id']);
        Area::findOrFail($data['area_id']);
        $this->assertGeometryMatchesType($data['geometry']['type'], $type);
        $data['attributes'] = app(\App\Services\Catalog\AttributeValidator::class)
            ->validate($type, $data['attributes'] ?? []);

        $asset = new Asset([
            ...collect($data)->except('geometry')->all(),
            'geom' => Geometry::toEwkb($data['geometry']),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
        $this->assertValidityDates($asset);
        $asset->save();

        if ($type->requires_tree_record) {
            \App\Models\Tree::create(['asset_id' => $asset->id, 'tenant_id' => $asset->tenant_id]);
        }
        if ($type->is_planting_site) {
            \App\Models\PlantingSite::create(['asset_id' => $asset->id, 'tenant_id' => $asset->tenant_id]);
        }

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
                'photos' => fn ($q) => $q->orderByDesc('created_at'),
                'tree',
                'plantingSite',
            ])
            ->withCount(['photos', 'documents', 'versions'])
            ->selectRaw('assets.*, ST_AsGeoJSON(geom)::json AS geom_geojson')
            ->withCasts(['geom_geojson' => 'array'])
            ->findOrFail($id);

        return response()->json(['data' => $asset]);
    }

    public function update(UpdateAssetRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($request, $id, $data) {
            // Lock pessimistico sulla riga: rende atomico il confronto di versione
            // (senza, due update concorrenti con la stessa versione passerebbero entrambi)
            $asset = Asset::query()->lockForUpdate()->findOrFail($id);

            if (isset($data['version']) && (int) $data['version'] !== $asset->version) {
                abort(409, "Conflitto di versione: la scheda è stata modificata da altri (versione attuale {$asset->version}).");
            }
            unset($data['version']);

            $type = isset($data['object_type_id'])
                ? CatalogObjectType::findOrFail($data['object_type_id'])
                : $asset->objectType;

            if (array_key_exists('geometry', $data)) {
                $this->assertGeometryMatchesType($data['geometry']['type'], $type);
                $data['geom'] = Geometry::toEwkb($data['geometry']);
                unset($data['geometry']);
            } elseif (isset($data['object_type_id'])) {
                // Cambio di tipo senza nuova geometria: la geometria esistente
                // deve essere compatibile con il nuovo tipo (422, non errore SQL)
                $current = DB::selectOne('SELECT GeometryType(geom) AS t FROM assets WHERE id = ?', [$asset->id])->t;
                $this->assertGeometryMatchesType($current, $type);
            }

            if (array_key_exists('area_id', $data)) {
                Area::findOrFail($data['area_id']);
            }

            if (array_key_exists('attributes', $data)) {
                $data['attributes'] = app(\App\Services\Catalog\AttributeValidator::class)
                    ->validate($type, $data['attributes'] ?? []);
            }

            $treeData = $data['tree'] ?? null;
            $siteData = $data['planting_site'] ?? null;
            unset($data['tree'], $data['planting_site']);

            $asset->fill($data);
            $asset->updated_by = $request->user()->id;
            $this->assertValidityDates($asset);
            $asset->save();

            $specializationChanged = false;

            if ($treeData !== null && $asset->tree) {
                $asset->tree->fill($treeData);
                $this->assertTreeDates($asset->tree);
                $specializationChanged = $asset->tree->isDirty();
                $asset->tree->save();
            }

            if ($siteData !== null && $asset->plantingSite) {
                if (! empty($siteData['previous_tree_id'])) {
                    // Deve essere un albero del tenant (404 se estraneo)
                    \App\Models\Tree::query()->findOrFail($siteData['previous_tree_id']);
                }
                $asset->plantingSite->fill($siteData);
                $specializationChanged = $specializationChanged || $asset->plantingSite->isDirty();
                $asset->plantingSite->save();
            }

            // Anche le modifiche a scheda albero/posto libero incrementano la versione
            // della scheda: senza questo, il lock ottimistico non vedrebbe i salvataggi
            // concorrenti che toccano solo la specializzazione (la riga assets non cambia)
            if ($specializationChanged && ! $asset->wasChanged()) {
                DB::update('UPDATE assets SET version = version + 1 WHERE id = ?', [$asset->id]);
            }

            Audit::log('asset.updated', $asset);
        });

        return $this->show($id);
    }

    public function destroy(string $id): Response
    {
        $asset = Asset::findOrFail($id);
        $asset->delete();

        Audit::log('asset.deleted', $asset);

        return response()->noContent();
    }

    private function assertGeometryMatchesType(string $geometryType, CatalogObjectType $type): void
    {
        $allowed = array_map('strtoupper', $type->allowedGeometryTypes());

        if (! in_array(strtoupper($geometryType), $allowed, true)) {
            throw ValidationException::withMessages([
                'geometry' => "Il tipo oggetto {$type->code} richiede una geometria ".
                    implode('/', $type->allowedGeometryTypes()).", ricevuta {$geometryType}.",
            ]);
        }
    }

    private function assertTreeDates(\App\Models\Tree $tree): void
    {
        if ($tree->removed_on && $tree->planted_on && $tree->removed_on->lt($tree->planted_on)) {
            throw ValidationException::withMessages([
                'tree.removed_on' => 'La data di rimozione non può precedere quella di impianto ('.$tree->planted_on->toDateString().').',
            ]);
        }
    }

    private function assertValidityDates(Asset $asset): void
    {
        $from = $asset->valid_from ?? now()->startOfDay();

        if ($asset->valid_to && $asset->valid_to->lt($from)) {
            throw ValidationException::withMessages([
                'valid_to' => 'La data di fine validità non può precedere quella di inizio ('.$from->toDateString().').',
            ]);
        }
    }
}
