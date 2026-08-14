<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Certificate extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    /** Entro quanti giorni una scadenza si considera "in scadenza". */
    public const DUE_SOON_DAYS = 60;

    protected $fillable = [
        'tenant_id', 'user_id', 'holder_name', 'title', 'number',
        'issued_by', 'issued_on', 'expires_on', 'notes',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'expires_on' => 'date',
            'version' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** Stato calcolato sulla data odierna italiana: scaduto, in scadenza, valido, senza scadenza. */
    public function state(): string
    {
        if ($this->expires_on === null) {
            return 'no_expiry';
        }
        // Confronto tra sole date in fuso italiano: i cast Carbon portano
        // orari e fusi che intorno alla mezzanotte falserebbero l'esito
        $expiry = $this->expires_on->toDateString();
        $today = now('Europe/Rome');
        if ($expiry < $today->toDateString()) {
            return 'expired';
        }

        return $expiry <= $today->addDays(self::DUE_SOON_DAYS)->toDateString() ? 'due_soon' : 'valid';
    }
}
