<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CatalogMainType;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    /** Catalogo completo del tenant: macro-categorie → tipi secondari → tipi oggetto. */
    public function index(): JsonResponse
    {
        $mainTypes = CatalogMainType::query()
            ->with([
                'subTypes' => fn ($q) => $q->orderBy('code'),
                'subTypes.objectTypes' => fn ($q) => $q->orderBy('code'),
            ])
            ->orderBy('code')
            ->get();

        $data = $mainTypes->map(fn (CatalogMainType $main) => [
            'id' => $main->id,
            'code' => $main->code,
            'name' => $main->name,
            'is_cam' => $main->is_cam,
            'sub_types' => $main->subTypes->map(fn ($sub) => [
                'id' => $sub->id,
                'code' => $sub->code,
                'name' => $sub->name,
                'object_types' => $sub->objectTypes->map(fn ($type) => [
                    'id' => $type->id,
                    'code' => $type->code,
                    'name' => $type->name,
                    'allowed_geometry' => $type->allowed_geometry,
                    'cam_layer' => $type->cam_layer,
                    'is_cam' => $type->is_cam,
                    'requires_tree_record' => $type->requires_tree_record,
                    'icon' => $type->icon,
                    'style' => $type->style,
                ]),
            ]),
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'object_types_count' => $data->sum(fn ($m) => $m['sub_types']->sum(fn ($s) => $s['object_types']->count())),
            ],
        ]);
    }
}
