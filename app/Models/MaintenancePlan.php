<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Piano di manutenzione pluriennale di un'area: "questa lavorazione, su
 * quest'area, ogni interval_months mesi" con finestra stagionale opzionale.
 * Il committente non si salva sul piano: si ricava sempre dalla catena
 * area > localita' > sede > committente, cosi' non puo' andare fuori sincrono.
 */
class MaintenancePlan extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'area_id', 'work_type_id', 'interval_months',
        'month_from', 'month_to', 'team_id', 'notes', 'is_active',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'interval_months' => 'integer',
            'month_from' => 'integer',
            'month_to' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function workType()
    {
        return $this->belongsTo(WorkType::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Il mese (1..12) cade nella finestra stagionale? Senza finestra tutto
     * l'anno va bene; con month_from > month_to la finestra scavalca il
     * capodanno (novembre-febbraio per le potature invernali).
     */
    public function meseInFinestra(int $mese): bool
    {
        if ($this->month_from === null || $this->month_to === null) {
            return true;
        }
        if ($this->month_from <= $this->month_to) {
            return $mese >= $this->month_from && $mese <= $this->month_to;
        }

        return $mese >= $this->month_from || $mese <= $this->month_to;
    }
}
