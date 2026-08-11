<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkType extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'category', 'unit', 'std_duration_min',
        'default_frequency', 'applicable_geometry', 'requires_photos', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_frequency' => 'array',
            'requires_photos' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
