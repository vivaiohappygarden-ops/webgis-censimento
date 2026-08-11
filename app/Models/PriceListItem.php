<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PriceListItem extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'price_list_id', 'work_type_id', 'item_code',
        'description', 'unit', 'unit_price', 'overhead_pct', 'safety_cost',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:4',
            'overhead_pct' => 'decimal:2',
            'safety_cost' => 'decimal:4',
        ];
    }

    public function workType()
    {
        return $this->belongsTo(WorkType::class);
    }
}
