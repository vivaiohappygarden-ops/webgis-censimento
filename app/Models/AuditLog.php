<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'user_id', 'action', 'subject_type', 'subject_id',
        'payload', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
