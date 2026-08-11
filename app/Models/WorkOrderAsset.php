<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkOrderAsset extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'work_order_id', 'asset_id', 'work_type_id',
        'planned_quantity', 'unit', 'status', 'notes',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function workType()
    {
        return $this->belongsTo(WorkType::class);
    }
}
