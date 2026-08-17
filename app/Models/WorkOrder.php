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

    /** Stati che compongono il working set di campo della PWA operatore. */
    public const FIELD_STATUSES = ['planned', 'assigned', 'in_progress', 'suspended'];

    /**
     * Ordini visibili dal campo: chi gestisce i lavori li vede tutti, un
     * operatore solo quelli assegnati a lui o a una sua squadra attiva.
     */
    public function scopeVisibleInField($query, User $user)
    {
        $query->whereIn('status', self::FIELD_STATUSES);

        if (! $user->can('works.manage')) {
            $query->where(function ($q) use ($user) {
                $q->where('assigned_to', $user->id)
                    ->orWhereIn('team_id', \Illuminate\Support\Facades\DB::table('team_members')
                        ->select('team_id')
                        ->where('user_id', $user->id)
                        ->whereNull('left_on'));
            });
        }

        return $query;
    }

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
            'completed_at' => 'datetime',
            'ppe' => 'array',
            'cancelled_days' => 'array',
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

    public function priceList()
    {
        return $this->belongsTo(PriceList::class);
    }

    public function assets()
    {
        return $this->hasMany(WorkOrderAsset::class);
    }

    public function logs()
    {
        return $this->hasMany(WorkLog::class);
    }

    public function checks()
    {
        return $this->hasMany(WorkCheck::class);
    }

    public function nonConformities()
    {
        return $this->hasMany(NonConformity::class);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** Numerazione per tenant: ODL-ANNO-progressivo, serializzata con advisory lock. */
    public static function nextCode(string $tenantId): string
    {
        \Illuminate\Support\Facades\DB::statement(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 42))', ["wo:{$tenantId}"],
        );
        $year = now()->year;
        $next = (int) \Illuminate\Support\Facades\DB::selectOne(
            "SELECT COALESCE(MAX((substring(code FROM '\\d+$'))::int), 0) + 1 AS n
             FROM work_orders WHERE tenant_id = ? AND code LIKE ?",
            [$tenantId, "ODL-{$year}-%"],
        )->n;

        return sprintf('ODL-%d-%04d', $year, $next);
    }
}
