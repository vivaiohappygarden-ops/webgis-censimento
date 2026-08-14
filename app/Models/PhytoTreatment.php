<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PhytoTreatment extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    public const METHODS = ['irrorazione', 'endoterapia', 'iniezione_suolo', 'esca', 'altro'];

    public const UNITS = ['l', 'ml', 'kg', 'g'];

    protected $fillable = [
        'tenant_id', 'area_id', 'asset_id', 'treated_on', 'product_name',
        'registration_number', 'active_substance', 'adversity', 'method',
        'quantity', 'unit', 'water_volume_l', 'surface_sqm', 'reentry_hours',
        'operator_id', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'treated_on' => 'date',
            'version' => 'integer',
            'reentry_hours' => 'integer',
        ];
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
