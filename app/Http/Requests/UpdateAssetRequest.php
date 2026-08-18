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
            // Esclusione dell'elemento dal portale pubblico del committente
            'public_hidden' => ['sometimes', 'boolean'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'geometry' => ['sometimes', 'array'],
            'geometry.type' => ['required_with:geometry', 'string', 'in:'.implode(',', Geometry::GEOJSON_TYPES)],
            'geometry.coordinates' => ['required_with:geometry', 'array'],

            // Scheda albero (applicata solo se l'asset ha il record trees)
            'tree' => ['sometimes', 'array'],
            'tree.plant_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'tree.genus' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tree.species' => ['sometimes', 'nullable', 'string', 'max:150'],
            'tree.cultivar' => ['sometimes', 'nullable', 'string', 'max:150'],
            'tree.family' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tree.common_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'tree.height_m' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:150'],
            'tree.dbh_cm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:2000'],
            'tree.trunk_circumference_cm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:6000'],
            'tree.trunk_count' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'tree.crown_diameter_m' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'tree.crown_insertion_m' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'tree.age_years_est' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3000'],
            'tree.age_class' => ['sometimes', 'nullable', 'string', 'max:50'],
            'tree.vegetative_state' => ['sometimes', 'nullable', 'string', 'max:50'],
            'tree.is_monumental' => ['sometimes', 'boolean'],
            'tree.monumental_ref' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tree.is_protected' => ['sometimes', 'boolean'],
            'tree.protection_ref' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tree.is_dedicated' => ['sometimes', 'boolean'],
            'tree.dedicated_to' => ['sometimes', 'array'],
            'tree.dedicated_to.name' => ['sometimes', 'nullable', 'string', 'max:254'],
            'tree.dedicated_to.occasion' => ['sometimes', 'nullable', 'string', 'max:254'],
            'tree.dedicated_to.date' => ['sometimes', 'nullable', 'date'],
            'tree.has_stake' => ['sometimes', 'boolean'],
            'tree.has_bracing' => ['sometimes', 'boolean'],
            'tree.bracing_notes' => ['sometimes', 'nullable', 'string'],
            'tree.planted_on' => ['sometimes', 'nullable', 'date'],
            'tree.removed_on' => ['sometimes', 'nullable', 'date'],
            'tree.removal_reason' => ['sometimes', 'nullable', 'string', 'max:254'],

            // Posto libero (applicato solo se l'asset ha il record planting_sites)
            'planting_site' => ['sometimes', 'array'],
            'planting_site.status' => ['sometimes', 'in:free,reserved,planted,unusable'],
            'planting_site.planned_species' => ['sometimes', 'nullable', 'string', 'max:150'],
            'planting_site.origin' => ['sometimes', 'nullable', 'in:felling,new_design,transplant,other'],
            'planting_site.previous_tree_id' => ['sometimes', 'nullable', 'uuid'],
            'planting_site.target_season' => ['sometimes', 'nullable', 'string', 'max:50'],
            'planting_site.notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
