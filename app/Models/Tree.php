<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tree extends Model
{
    use BelongsToTenant, HasFactory;

    protected $primaryKey = 'asset_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'asset_id', 'tenant_id', 'plant_number', 'genus', 'species', 'cultivar',
        'family', 'common_name', 'height_m', 'dbh_cm', 'trunk_circumference_cm',
        'trunk_count', 'crown_diameter_m', 'crown_insertion_m', 'age_years_est',
        'age_class', 'age_qualifier', 'vegetative_state', 'social_position',
        'target', 'growth_site', 'is_monumental', 'monumental_ref',
        'is_protected', 'protection_ref', 'is_dedicated', 'dedicated_to',
        'has_stake', 'has_bracing', 'bracing_notes', 'planted_on', 'removed_on',
        'removal_reason',
    ];

    protected function casts(): array
    {
        return [
            'height_m' => 'decimal:2',
            'dbh_cm' => 'decimal:1',
            'trunk_circumference_cm' => 'decimal:1',
            'crown_diameter_m' => 'decimal:2',
            'crown_insertion_m' => 'decimal:2',
            'trunk_count' => 'integer',
            'age_years_est' => 'integer',
            'is_monumental' => 'boolean',
            'is_protected' => 'boolean',
            'is_dedicated' => 'boolean',
            'dedicated_to' => 'array',
            'has_stake' => 'boolean',
            'has_bracing' => 'boolean',
            'planted_on' => 'date',
            'removed_on' => 'date',
        ];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function assessments()
    {
        return $this->hasMany(TreeAssessment::class, 'tree_id', 'asset_id');
    }
}
