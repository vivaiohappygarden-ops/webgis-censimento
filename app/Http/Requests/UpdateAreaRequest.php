<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'locality_id' => ['sometimes', 'uuid'],
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
            'code' => ['sometimes', 'nullable', 'string', 'max:80'],
            'name' => ['sometimes', 'string', 'max:254'],
            'area_type' => ['sometimes', 'in:management,functional,temporary'],
            'status' => ['sometimes', 'in:planned,active,suspended,dismissed'],
            'manager' => ['sometimes', 'nullable', 'string', 'max:100'],
            'street_code' => ['sometimes', 'nullable', 'string', 'max:12'],
            'valid_from' => ['sometimes', 'date'],
            'valid_to' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'geometry' => ['sometimes', 'array'],
            'geometry.type' => ['required_with:geometry', 'string', 'in:Polygon,MultiPolygon'],
            'geometry.coordinates' => ['required_with:geometry', 'array'],
        ];
    }
}
