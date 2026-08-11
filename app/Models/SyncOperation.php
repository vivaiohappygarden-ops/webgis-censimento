<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SyncOperation extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'idempotency_key', 'batch_id', 'device_id', 'user_id',
        'command_type', 'entity_id', 'status', 'result',
    ];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }
}
