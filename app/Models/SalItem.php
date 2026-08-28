<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Una riga di SAL: un ordine completato, valorizzato dal listino. */
class SalItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'sal_id', 'work_order_id', 'descrizione', 'unit',
        'quantity', 'unit_price', 'imponibile', 'vat_rate', 'nota', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'imponibile' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
