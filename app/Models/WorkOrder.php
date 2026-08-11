<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrder extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /** Flusso di default (configurabile per tenant nelle fasi successive). */
    public const STATUSES = ['draft', 'planned', 'assigned', 'in_progress', 'suspended', 'completed', 'cancelled'];

    public const TRANSITIONS = [
        'draft' => ['planned', 'cancelled'],
        'planned' => ['assigned', 'draft', 'cancelled'],
        'assigned' => ['in_progress', 'planned', 'cancelled'],
        'in_progress' => ['suspended', 'completed', 'cancelled'],
        'suspended' => ['in_progress', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    protected $fillable = [
        'tenant_id', 'code', 'client_id', 'contract_id', 'site_id', 'area_id',
        'work_type_id', 'title', 'description', 'status', 'priority', 'origin',
        'origin_id', 'planned_start', 'planned_end', 'due_at',
        'estimated_duration_min', 'team_id', 'assigned_to', 'price_list_id',
        'risks', 'ppe', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'planned_start' => 'date',
            'planned_end' => 'date',
            'due_at' => 'datetime',
            'ppe' => 'array',
            'version' => 'integer',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function workType()
    {
        return $this->belongsTo(WorkType::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assets()
    {
        return $this->hasMany(WorkOrderAsset::class);
    }

    public function logs()
    {
        return $this->hasMany(WorkLog::class);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }
}
