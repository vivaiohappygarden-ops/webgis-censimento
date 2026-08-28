<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Richiesta di riprogrammazione di un ordine, fatta da un'impresa esterna.
 *
 * Il motivo e' codificato (tendina, non testo libero): serve al gestionale
 * per leggere in un colpo d'occhio perche' i lavori slittano. La richiesta
 * resta agli atti anche dopo la decisione.
 */
class RescheduleRequest extends Model
{
    use BelongsToTenant, HasUuids;

    /** I motivi codificati, mostrati cosi' come sono scritti. */
    public const MOTIVI = [
        'maltempo' => 'Maltempo',
        'mezzi' => 'Mezzi o attrezzature non disponibili',
        'personale' => 'Personale non disponibile',
        'accesso' => 'Accesso all\'area non possibile',
        'materiale' => 'Materiale non arrivato',
        'altro' => 'Altro (spiegare nelle note)',
    ];

    public const STATI = ['aperta', 'accettata', 'rifiutata'];

    protected $fillable = [
        'tenant_id', 'work_order_id', 'team_id', 'requested_by', 'reason',
        'proposed_start', 'notes', 'status', 'response_note', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'proposed_start' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
