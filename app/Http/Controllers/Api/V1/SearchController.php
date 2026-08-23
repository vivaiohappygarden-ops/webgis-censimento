<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetTag;
use App\Models\CatalogObjectType;
use App\Models\Client;
use App\Models\Locality;
use App\Support\RicercaTestuale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/** Ricerca globale: un unico campo che trova elementi, aree, località, tipi e tag. */
class SearchController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:assets.view')];
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'min:2', ...RicercaTestuale::regole()]]);
        $q = $data['q'];

        // Tag fisico: corrispondenza esatta sull'UID (scan/tap)
        $tagAsset = null;
        $tag = AssetTag::query()
            ->where('uid', $q)
            ->whereIn('status', ['active'])
            ->whereNotNull('asset_id')
            ->first();
        if ($tag) {
            $tagAsset = Asset::query()->with('objectType:id,code,name')->find($tag->asset_id);
        }

        $assets = Asset::query()
            ->with('objectType:id,code,name')
            ->cercaTesto($q)
            ->orderBy('census_code')
            ->limit(8)
            ->get(['id', 'census_code', 'object_type_id', 'status']);

        $areas = Area::query()
            ->tap(fn ($w) => RicercaTestuale::applica($w, $q, ['name', 'code']))
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'code', 'status']);

        $localities = Locality::query()
            ->with('site:id,name')
            ->tap(fn ($w) => RicercaTestuale::applica($w, $q, ['name', 'code']))
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'code', 'site_id']);

        $types = CatalogObjectType::query()
            ->tap(fn ($w) => RicercaTestuale::applica($w, $q, ['code', 'name']))
            ->orderBy('code')
            ->limit(6)
            ->get(['id', 'code', 'name', 'allowed_geometry']);

        // I committenti mancavano: cercando "Rossi" nella ricerca rapida non
        // usciva il committente Rossi, che e' il primo posto dove si guarda.
        //
        // Ma solo a chi l'anagrafica la puo' vedere: la rotta chiede
        // assets.view, che ha anche l'operatore, e l'elenco dei committenti
        // non e' roba sua. Cercare non deve mostrare piu' di quanto si veda
        // aprendo le pagine.
        $clients = $request->user()->can('clients.view')
            ? Client::query()
                ->tap(fn ($w) => RicercaTestuale::applica($w, $q, ['name', 'code']))
                ->orderBy('name')
                ->limit(5)
                ->get(['id', 'name', 'code', 'client_type'])
            : collect();

        return response()->json([
            'data' => [
                'tag_match' => $tagAsset ? [
                    'tag_uid' => $tag->uid,
                    'tag_type' => $tag->tag_type,
                    'asset' => $tagAsset->only(['id', 'census_code', 'status']) + [
                        'type_name' => $tagAsset->objectType?->name,
                    ],
                ] : null,
                'assets' => $assets->map(fn ($a) => [
                    'id' => $a->id,
                    'census_code' => $a->census_code,
                    'status' => $a->status,
                    'type_code' => $a->objectType?->code,
                    'type_name' => $a->objectType?->name,
                ]),
                'areas' => $areas,
                'localities' => $localities->map(fn ($l) => [
                    'id' => $l->id, 'name' => $l->name, 'code' => $l->code,
                    'site_name' => $l->site?->name,
                ]),
                'clients' => $clients,
                'catalog_types' => $types,
            ],
        ]);
    }
}
