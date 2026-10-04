<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\NelPerimetroZona;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    /** Come il modello si lega al committente per il perimetro di zona (ZonaScope). */
    public const PERIMETRO_ZONA = 'client_id';

    use BelongsToTenant, NelPerimetroZona, HasFactory, HasUuids, SoftDeletes;

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
