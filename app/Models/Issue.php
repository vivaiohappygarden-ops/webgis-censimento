<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Issue extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    public const STATUSES = ['open', 'in_charge', 'resolved', 'dismissed'];

    /** Presa in carico, poi risoluzione; l'archiviazione chiude senza intervento. */
    public const TRANSITIONS = [
        'open' => ['in_charge', 'dismissed'],
        'in_charge' => ['resolved', 'dismissed', 'open'],
        'resolved' => [],
        'dismissed' => [],
    ];

    protected $fillable = [
        'tenant_id', 'code', 'reporter_type', 'reporter_user_id', 'reporter_name',
        'reporter_contact', 'channel', 'category', 'severity', 'status',
        'asset_id', 'area_id', 'geom', 'description', 'sla_due_at',
        'taken_charge_at', 'resolved_at', 'resolution_notes', 'work_order_id',
    ];

    protected function casts(): array
    {
        return [
            'sla_due_at' => 'datetime',
            'taken_charge_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }
}
