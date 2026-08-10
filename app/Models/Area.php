<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Area extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'locality_id', 'parent_id', 'code', 'name', 'area_type',
        'status', 'manager', 'street_code', 'geom', 'valid_from', 'valid_to',
        'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'computed_area_sqm' => 'decimal:2',
            'computed_perimeter_m' => 'decimal:2',
        ];
    }

    public function locality()
    {
        return $this->belongsTo(Locality::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }
}
