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

    /** Numerazione per tenant: NC-ANNO-progressivo, serializzata con advisory lock. */
    public static function nextCode(string $tenantId): string
    {
        \Illuminate\Support\Facades\DB::statement(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 43))', ["nc:{$tenantId}"],
        );
        $year = now()->year;
        $next = (int) \Illuminate\Support\Facades\DB::selectOne(
            "SELECT COALESCE(MAX((substring(code FROM '\\d+$'))::int), 0) + 1 AS n
             FROM non_conformities WHERE tenant_id = ? AND code LIKE ?",
            [$tenantId, "NC-{$year}-%"],
        )->n;

        return sprintf('NC-%d-%04d', $year, $next);
    }
}
