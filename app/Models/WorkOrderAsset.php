<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\NelPerimetroZona;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkOrderAsset extends Model
{
    /** Come il modello si lega al committente per il perimetro di zona (ZonaScope). */
    public const PERIMETRO_ZONA = 'asset_id';

    use BelongsToTenant, NelPerimetroZona, HasUuids;

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
