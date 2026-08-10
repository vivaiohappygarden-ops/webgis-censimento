<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'client_id', 'code', 'title', 'contract_type', 'cig', 'cup',
        'starts_on', 'ends_on', 'amount', 'currency', 'status', 'sla', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'amount' => 'decimal:2',
            'sla' => 'array',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
