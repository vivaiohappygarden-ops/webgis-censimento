<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class GestionaleDispatch extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'gestionale_dispatches';

    public const TYPES = ['intervento', 'preventivo'];

    public const PRIORITIES = ['bassa', 'media', 'alta', 'critica'];

    protected $fillable = [
        'tenant_id', 'asset_id', 'external_ref', 'request_type', 'title',
        'description', 'priority', 'photo_ids', 'status', 'attempts',
        'last_error', 'remote_id', 'remote_url', 'is_duplicate', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'photo_ids' => 'array',
            'attempts' => 'integer',
            'is_duplicate' => 'boolean',
        ];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class)->withTrashed();
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /** Riferimento progressivo per anno: la chiave di idempotenza lato gestionale. */
    public static function nextRef(string $tenantId): string
    {
        DB::statement(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 42))', ["ygc:{$tenantId}"],
        );
        $year = now('Europe/Rome')->year;
        $next = (int) DB::selectOne(
            "SELECT COALESCE(MAX((substring(external_ref FROM '\\d+$'))::int), 0) + 1 AS n
             FROM gestionale_dispatches WHERE tenant_id = ? AND external_ref LIKE ?",
            [$tenantId, "WG-{$year}-%"],
        )->n;

        return sprintf('WG-%d-%06d', $year, $next);
    }
}
