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

    protected $appends = ['sla'];

    /** Scadenze SLA (presa in carico e risoluzione) con il loro stato. */
    public function getSlaAttribute(): ?array
    {
        return \App\Support\IssueSla::describe($this);
    }

    /** Numerazione per tenant: SEG-ANNO-progressivo, serializzata con advisory lock. */
    public static function nextCode(string $tenantId): string
    {
        \Illuminate\Support\Facades\DB::statement(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 44))', ["issue:{$tenantId}"],
        );
        $year = now()->year;
        $next = (int) \Illuminate\Support\Facades\DB::selectOne(
            "SELECT COALESCE(MAX((substring(code FROM '\\d+$'))::int), 0) + 1 AS n
             FROM issues WHERE tenant_id = ? AND code LIKE ?",
            [$tenantId, "SEG-{$year}-%"],
        )->n;

        return sprintf('SEG-%d-%04d', $year, $next);
    }
}
