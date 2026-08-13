<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CatalogObjectType;
use App\Models\CustomField;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class CustomFieldController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:catalog.view', only: ['index']),
            new Middleware('can:catalog.manage', only: ['store', 'update', 'destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['object_type_id' => ['required', 'uuid']]);
        $type = CatalogObjectType::findOrFail($request->string('object_type_id'));

        return response()->json([
            'data' => $type->customFields()->orderBy('sort_order')->orderBy('label')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        CatalogObjectType::findOrFail($data['object_type_id']);

        $field = CustomField::create($data);
        Audit::log('catalog.field_created', $field, ['key' => $field->key]);

        return response()->json(['data' => $field], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $field = CustomField::findOrFail($id);
        $data = $this->validated($request, $field);
        unset($data['object_type_id'], $data['key']); // tipo e chiave sono immutabili

        $field->update($data);
        Audit::log('catalog.field_updated', $field);

        return response()->json(['data' => $field->fresh()]);
    }

    public function destroy(string $id): Response
    {
        $field = CustomField::findOrFail($id);
        $field->delete();
        Audit::log('catalog.field_deleted', $field, ['key' => $field->key]);

        return response()->noContent();
    }

    private function validated(Request $request, ?CustomField $existing = null): array
    {
        return $request->validate([
            'object_type_id' => [$existing ? 'sometimes' : 'required', 'uuid'],
            'key' => [
                $existing ? 'sometimes' : 'required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('custom_fields', 'key')->where(fn ($q) => $q
                    ->where('tenant_id', $request->user()->tenant_id)
                    ->where('object_type_id', $existing?->object_type_id ?? $request->input('object_type_id'))
                    ->whereNull('deleted_at'))
                    ->ignore($existing?->id),
            ],
            'label' => [$existing ? 'sometimes' : 'required', 'string', 'max:120'],
            'field_type' => [
                $existing ? 'sometimes' : 'required',
                'in:text,textarea,integer,number,boolean,date,select,multiselect,photo,url',
            ],
            'required' => ['sometimes', 'boolean'],
            'options' => ['sometimes', 'array', 'max:200'],
            'options.*' => ['string', 'max:120'],
            'validation' => ['sometimes', 'array'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            // Campo del tracciato CAM alimentato da questo campo custom
            // all'export (GIS-DATA-MODEL §6.3.4), es. LARG_m
            'cam_field' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'show_in_pwa' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ]);
    }
}
