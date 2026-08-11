<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkLog extends Model
{
    use BelongsToTenant, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'tenant_id', 'work_order_id', 'asset_id', 'team_id', 'operator_id',
        'started_at', 'start_geom', 'ended_at', 'end_geom', 'man_hours',
        'quantity', 'unit', 'vehicles', 'equipment', 'materials', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'vehicles' => 'array',
            'equipment' => 'array',
            'materials' => 'array',
        ];
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
