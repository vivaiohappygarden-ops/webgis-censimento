<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Stato di avanzamento lavori: la fotografia valorizzata dei lavori
 * completati di un committente in un periodo. Bozza finche' non viene
 * validato a mano; da li' e' immutabile e porta il numero.
 */
class Sal extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATI = ['bozza', 'validato', 'fatturato'];

    protected $fillable = [
        'tenant_id', 'client_id', 'code', 'period_from', 'period_to', 'status',
        'notes', 'overhead_pct', 'validated_at', 'validated_by',
        'invoiced_at', 'invoiced_by', 'invoice_ref', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'overhead_pct' => 'decimal:2',
            'validated_at' => 'datetime',
            'invoiced_at' => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function items()
    {
        return $this->hasMany(SalItem::class)->orderBy('sort_order')->orderBy('created_at');
    }

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}
