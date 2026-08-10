<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AssetVersion extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    public const CREATED_AT = 'changed_at';

    protected $fillable = [
        'tenant_id', 'asset_id', 'version', 'snapshot', 'diff', 'geom',
        'changed_by', 'change_source',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'diff' => 'array',
            'version' => 'integer',
            'changed_at' => 'datetime',
        ];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }
}
