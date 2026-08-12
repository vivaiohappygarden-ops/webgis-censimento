<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InspectionTemplate extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'target', 'object_type_id',
        'standard_ref', 'frequency_days', 'version', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'frequency_days' => 'integer',
            'version' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function items()
    {
        return $this->hasMany(ChecklistItem::class, 'template_id')->orderBy('sort_order');
    }

    public function inspections()
    {
        return $this->hasMany(Inspection::class, 'template_id');
    }
}
