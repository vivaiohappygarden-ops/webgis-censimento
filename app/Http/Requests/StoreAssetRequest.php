<?php

namespace App\Http\Requests;

use App\Support\Geometry;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'area_id' => ['required', 'uuid'],
            'object_type_id' => ['required', 'uuid'],
            'census_code' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'string', 'max:50'],
            'survey_method' => ['nullable', 'in:gps,gps_rtk,digitized,cad_import,shapefile_import,manual_map,estimated'],
            'gps_accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'surveyed_at' => ['nullable', 'date'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'attributes' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
            ...Geometry::rules(),
        ];
    }
}
