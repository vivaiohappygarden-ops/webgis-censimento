<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Estimate extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    public const STATUSES = ['draft', 'sent', 'accepted', 'rejected'];

    /** Flusso commerciale lineare: si modifica solo la bozza. */
    public const TRANSITIONS = [
        'draft' => ['sent'],
        'sent' => ['accepted', 'rejected', 'draft'],
        'accepted' => [],
        'rejected' => ['draft'],
    ];

    protected $fillable = [
        'tenant_id', 'code', 'client_id', 'area_id', 'title', 'status',
        'vat_percent', 'valid_until', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
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

    public function items()
    {
        return $this->hasMany(EstimateItem::class)->orderBy('sort_order');
    }

    public static function nextCode(string $tenantId): string
    {
        DB::statement(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 42))', ["est:{$tenantId}"],
        );
        // Fuso di riferimento del committente: a cavallo di Capodanno il
        // progressivo riparte quando riparte l'anno in Italia, non in UTC
        $year = now('Europe/Rome')->year;
        $next = (int) DB::selectOne(
            "SELECT COALESCE(MAX((substring(code FROM '\\d+$'))::int), 0) + 1 AS n
             FROM estimates WHERE tenant_id = ? AND code LIKE ?",
            [$tenantId, "PRE-{$year}-%"],
        )->n;

        return sprintf('PRE-%d-%04d', $year, $next);
    }
}
