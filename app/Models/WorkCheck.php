<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\NelPerimetroZona;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkCheck extends Model
{
    /** Come il modello si lega al committente per il perimetro di zona (ZonaScope). */
    public const PERIMETRO_ZONA = 'work_order_id';

    use BelongsToTenant, NelPerimetroZona, HasUuids;

    protected $fillable = [
        'tenant_id', 'work_order_id', 'checked_by', 'checked_at', 'outcome', 'notes',
    ];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function checker()
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
