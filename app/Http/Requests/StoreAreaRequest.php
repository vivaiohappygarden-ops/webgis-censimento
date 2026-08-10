<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'locality_id' => ['required', 'uuid'],
            'parent_id' => ['nullable', 'uuid'],
            'code' => ['nullable', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:254'],
            'area_type' => ['nullable', 'in:management,functional,temporary'],
            'status' => ['nullable', 'in:planned,active,suspended,dismissed'],
            'manager' => ['nullable', 'string', 'max:100'],
            'street_code' => ['nullable', 'string', 'max:12'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'notes' => ['nullable', 'string'],
            'geometry' => ['required', 'array'],
            'geometry.type' => ['required', 'string', 'in:Polygon,MultiPolygon'],
            'geometry.coordinates' => ['required', 'array'],
        ];
    }
}
