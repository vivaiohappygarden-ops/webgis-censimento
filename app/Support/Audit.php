<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class Audit
{
    public static function log(string $action, ?Model $subject = null, array $payload = []): void
    {
        self::scrivi(Auth::user()?->tenant_id, Auth::id(), $action, $subject, $payload);
    }

    /**
     * A nome di un utente non ancora autenticato: il secondo passaggio
     * dell'accesso sa chi sta entrando prima che la sessione lo dica.
     */
    public static function logPer(User $user, string $action, ?Model $subject = null, array $payload = []): void
    {
        self::scrivi($user->tenant_id, $user->id, $action, $subject ?? $user, $payload);
    }

    private static function scrivi(?string $tenantId, ?string $userId, string $action, ?Model $subject, array $payload): void
    {
        AuditLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'payload' => $payload,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 500),
        ]);
    }
}
