<?php

namespace App\Http\Requests;

use App\Support\Geometry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'area_id' => ['sometimes', 'uuid'],
            'object_type_id' => ['sometimes', 'uuid'],
            'census_code' => ['sometimes', 'nullable', 'string', 'max:80',
                Rule::unique('assets', 'census_code')->ignore($this->route('asset'))->where(
                    fn ($q) => $q->where('tenant_id', $this->user()->tenant_id)->whereNull('deleted_at')
                ),
            ],
            'status' => ['sometimes', 'string', 'max:50'],
            'survey_method' => ['sometimes', 'nullable', 'in:gps,gps_rtk,digitized,cad_import,shapefile_import,manual_map,estimated'],
            'gps_accuracy_m' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'surveyed_at' => ['sometimes', 'nullable', 'date'],
            'valid_from' => ['sometimes', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date'],
            'attributes' => ['sometimes', 'array'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'geometry' => ['sometimes', 'array'],
            'geometry.type' => ['required_with:geometry', 'string', 'in:'.implode(',', Geometry::GEOJSON_TYPES)],
            'geometry.coordinates' => ['required_with:geometry', 'array'],
        ];
    }
}
