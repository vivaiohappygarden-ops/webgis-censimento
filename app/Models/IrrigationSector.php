<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IrrigationSector extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'system_id', 'sort_order', 'name', 'description',
        'flow_lpm', 'run_minutes', 'runs_per_week',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'run_minutes' => 'integer',
            'runs_per_week' => 'integer',
        ];
    }

    public function system()
    {
        return $this->belongsTo(IrrigationSystem::class, 'system_id');
    }
}
