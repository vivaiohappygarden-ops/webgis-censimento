<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\NelPerimetroZona;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Avviso al committente nato da una valutazione di stabilita': "l'area va
 * chiusa o interdetta fino all'intervento". Vedi App\Services\Vta\AvvisoCommittente.
 */
class ClientAlert extends Model
{
    use BelongsToTenant, HasUuids, NelPerimetroZona;

    public const PERIMETRO_ZONA = 'client_id';

    public const KIND_AREA_CLOSURE = 'area_closure';

    protected $fillable = [
        'tenant_id', 'client_id', 'asset_id', 'assessment_id', 'area_id', 'kind', 'failure_class', 'message',
        'sent_by', 'sent_at', 'recipients', 'acknowledged_at', 'acknowledged_by', 'acknowledged_note',
        'resolved_at', 'resolved_by', 'resolved_note',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'sent_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function assessment()
    {
        return $this->belongsTo(TreeAssessment::class, 'assessment_id');
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function acknowledger()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** Quanti destinatari hanno ricevuto davvero l'email. */
    public function inviati(): int
    {
        return count(array_filter($this->recipients ?? [], fn ($d) => ($d['esito'] ?? null) === 'inviata'));
    }
}
