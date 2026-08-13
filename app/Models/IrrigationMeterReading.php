<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IrrigationMeterReading extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'system_id', 'read_on', 'value_m3', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'read_on' => 'date',
        ];
    }

    public function system()
    {
        return $this->belongsTo(IrrigationSystem::class, 'system_id');
    }
}
