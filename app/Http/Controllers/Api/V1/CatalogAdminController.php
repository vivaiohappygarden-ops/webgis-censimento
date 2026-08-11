<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CatalogObjectType;
use App\Models\CatalogSubType;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Gestione amministrativa del catalogo: tipi oggetto personalizzati del tenant. */
class CatalogAdminController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:catalog.manage')];
    }

    public function storeObjectType(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sub_type_id' => ['required', 'uuid'],
            'code' => [
                'required', 'string', 'max:10', 'regex:/^[A-Z0-9\-]+$/',
                Rule::unique('catalog_object_types', 'code')->where(fn ($q) => $q
                    ->where('tenant_id', $request->user()->tenant_id)->whereNull('deleted_at')),
            ],
            'name' => ['required', 'string', 'max:254'],
            'allowed_geometry' => ['required', 'in:P,L,S'],
            'survey_mode' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:20'],
            'style' => ['sometimes', 'array'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'requires_tree_record' => ['sometimes', 'boolean'],
            'is_planting_site' => ['sometimes', 'boolean'],
        ]);

        CatalogSubType::findOrFail($data['sub_type_id']);

        $type = CatalogObjectType::create([...$data, 'is_cam' => false]);
        Audit::log('catalog.type_created', $type, ['code' => $type->code]);

        return response()->json(['data' => $type], 201);
    }

    public function updateObjectType(Request $request, string $id): JsonResponse
    {
        $type = CatalogObjectType::findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:254'],
            'survey_mode' => ['sometimes', 'nullable', 'string', 'max:500'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:20'],
            'style' => ['sometimes', 'array'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'allowed_geometry' => ['sometimes', 'in:P,L,S'],
            'requires_tree_record' => ['sometimes', 'boolean'],
            'is_planting_site' => ['sometimes', 'boolean'],
        ]);

        // Il set CAM è lo standard ministeriale: codice, nome, geometria e
        // comportamento (scheda albero, posto libero) restano fissi
        if ($type->is_cam) {
            unset($data['name'], $data['allowed_geometry'],
                $data['requires_tree_record'], $data['is_planting_site']);
        } elseif (isset($data['allowed_geometry'])
            && $data['allowed_geometry'] !== $type->allowed_geometry
            && $type->assets()->exists()) {
            throw ValidationException::withMessages([
                'allowed_geometry' => 'Impossibile cambiare la geometria: esistono elementi censiti con questo tipo.',
            ]);
        }

        $type->update($data);
        Audit::log('catalog.type_updated', $type);

        return response()->json(['data' => $type->fresh()]);
    }

    public function destroyObjectType(string $id): Response
    {
        $type = CatalogObjectType::findOrFail($id);

        if ($type->is_cam) {
            throw ValidationException::withMessages([
                'type' => 'I tipi del Modello Dati ministeriale non possono essere eliminati.',
            ]);
        }
        if ($type->assets()->exists()) {
            throw ValidationException::withMessages([
                'type' => 'Esistono elementi censiti con questo tipo: riclassificali prima di eliminarlo.',
            ]);
        }

        $type->delete();
        Audit::log('catalog.type_deleted', $type, ['code' => $type->code]);

        return response()->noContent();
    }
}
