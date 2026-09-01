<?php

namespace App\Http\Requests;

use App\Support\Geometry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'census_code' => ['nullable', 'string', 'max:80',
                Rule::unique('assets', 'census_code')->where(
                    fn ($q) => $q->where('tenant_id', $this->user()->tenant_id)->whereNull('deleted_at')
                ),
            ],
            // Una scheda non nasce in archivio: abbattuti e dismessi si
            // registrano dai loro flussi su schede esistenti (l'import CAM,
            // che può portare storici, non passa da questa richiesta)
            'status' => ['nullable', 'string', Rule::in(['active', 'dead', 'stump'])],
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
