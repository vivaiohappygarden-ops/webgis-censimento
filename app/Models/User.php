<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use BelongsToTenant, HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable, SoftDeletes;

    protected string $guard_name = 'web';

    protected $fillable = [
        'tenant_id', 'name', 'email', 'username', 'password', 'phone',
        'user_type', 'locale', 'is_active', 'client_id', 'notify_email',
    ];

    /** Il cliente a cui l'utente del portale è agganciato. */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    protected $hidden = [
        // calendar_token è nascosto perché gli elenchi utenti (pagina Utenti)
        // serializzano il modello: il gettone dà accesso all'agenda personale
        // e lo deve vedere solo il proprietario, dal pannello del calendario
        'password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes', 'mfa_last_step', 'calendar_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'settings' => 'array',
            'mfa_enabled' => 'boolean',
            'is_platform_manager' => 'boolean',
            // Il segreto della verifica in due passaggi vive cifrato con la chiave dell'applicazione
            'mfa_secret' => 'encrypted',
            'mfa_confirmed_at' => 'datetime',
            'mfa_recovery_codes' => 'array',
            'notify_email' => 'boolean',
        ];
    }
}
