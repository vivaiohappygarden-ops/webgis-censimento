<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class IrrigationSystem extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    public const TYPES = ['aspersione', 'goccia', 'subirrigazione', 'idranti', 'misto'];

    public const WATER_SOURCES = ['acquedotto', 'pozzo', 'cisterna', 'corso_acqua', 'altro'];

    public const STATUSES = ['active', 'winterized', 'out_of_service'];

    protected $fillable = [
        'tenant_id', 'area_id', 'name', 'system_type', 'water_source',
        'controller_model', 'status', 'season_opens_on', 'season_closes_on', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'season_opens_on' => 'date',
            'season_closes_on' => 'date',
        ];
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function sectors()
    {
        return $this->hasMany(IrrigationSector::class, 'system_id')->orderBy('sort_order');
    }
}
