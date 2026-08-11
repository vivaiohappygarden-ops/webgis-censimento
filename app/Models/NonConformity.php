<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NonConformity extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /** Flusso correttivo lineare: apertura, azione, verifica, chiusura. */
    public const STATUSES = ['open', 'action', 'verified', 'closed'];

    public const TRANSITIONS = [
        'open' => ['action', 'closed'],
        'action' => ['verified', 'open'],
        'verified' => ['closed', 'action'],
        'closed' => [],
    ];

    protected $table = 'non_conformities';

    protected $fillable = [
        'tenant_id', 'code', 'origin', 'origin_id', 'severity', 'status',
        'asset_id', 'work_order_id', 'description', 'root_cause',
        'corrective_action', 'responsible_id', 'detected_on', 'due_on',
        'closed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'detected_on' => 'date',
            'due_on' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function responsible()
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }
}
