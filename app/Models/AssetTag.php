<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssetTag extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'asset_id', 'tag_type', 'uid', 'payload', 'status',
        'attached_by', 'attached_at', 'detached_at', 'detach_reason',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attached_at' => 'datetime',
            'detached_at' => 'datetime',
        ];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }
}
