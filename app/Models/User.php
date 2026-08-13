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
        'user_type', 'locale', 'is_active', 'client_id',
    ];

    /** Il cliente a cui l'utente del portale è agganciato. */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    protected $hidden = [
        'password', 'remember_token', 'mfa_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'mfa_enabled' => 'boolean',
        ];
    }
}
