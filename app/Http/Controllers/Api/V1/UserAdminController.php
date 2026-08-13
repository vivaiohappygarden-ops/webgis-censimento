<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gestione degli utenti del tenant da parte dell'amministratore: creazione
 * con ruolo, disattivazione (mai cancellazione: la storia resta), reset
 * password. Le guardie impediscono di chiudersi fuori: niente
 * autodisattivazione né rimozione dell'ultimo amministratore attivo.
 */
class UserAdminController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:users.manage')];
    }

    public function index(): JsonResponse
    {
        $users = User::query()
            ->with('client:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'user_type' => $user->user_type,
                'is_active' => $user->is_active,
                'role' => $user->getRoleNames()->first(),
                'client' => $user->client?->only(['id', 'name']),
                'last_login_at' => $user->last_login_at,
            ]);

        return response()->json(['data' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(array_keys(TenantProvisioner::ROLES))],
            'client_id' => ['required_if:role,cliente', 'nullable', 'uuid'],
        ]);

        if ($data['role'] === 'cliente') {
            Client::query()->findOrFail($data['client_id']);
        }

        $temporaryPassword = $this->temporaryPassword();

        try {
            $user = DB::transaction(function () use ($request, $data, $temporaryPassword) {
                $user = User::create([
                    'tenant_id' => $request->user()->tenant_id,
                    'name' => $data['name'],
                    'email' => mb_strtolower(trim($data['email'])),
                    'password' => $temporaryPassword,
                    'user_type' => $data['role'] === 'cliente' ? 'client_portal' : 'internal',
                    'client_id' => $data['role'] === 'cliente' ? $data['client_id'] : null,
                ]);
                $user->assignRole($data['role']);

                return $user;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => 'Esiste già un utente con questo indirizzo email.',
            ]);
        }

        Audit::log('user.created', $user, ['email' => $user->email, 'role' => $data['role']]);

        return response()->json([
            'data' => $this->presented($user),
            // Mostrata una sola volta: viene salvata solo la versione cifrata
            'temporary_password' => $temporaryPassword,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $me = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'role' => ['sometimes', Rule::in(array_keys(TenantProvisioner::ROLES))],
            'client_id' => ['nullable', 'uuid'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $newRole = $data['role'] ?? $user->getRoleNames()->first();
        $deactivating = array_key_exists('is_active', $data) && ! $data['is_active'];

        if ($user->id === $me->id && ($deactivating || $newRole !== 'amministratore')) {
            throw ValidationException::withMessages([
                'is_active' => 'Non puoi disattivare o declassare il tuo stesso account.',
            ]);
        }
        $this->guardLastAdministrator($user, $newRole, $deactivating);

        if ($newRole === 'cliente') {
            $clientId = $data['client_id'] ?? $user->client_id;
            if (! $clientId) {
                throw ValidationException::withMessages([
                    'client_id' => 'Il ruolo cliente richiede il collegamento a un cliente.',
                ]);
            }
            Client::query()->findOrFail($clientId);
            $data['client_id'] = $clientId;
        }

        DB::transaction(function () use ($user, $data, $newRole) {
            $user->fill([
                'name' => $data['name'] ?? $user->name,
                'is_active' => $data['is_active'] ?? $user->is_active,
                'user_type' => $newRole === 'cliente' ? 'client_portal' : 'internal',
                'client_id' => $newRole === 'cliente' ? $data['client_id'] : null,
            ])->save();
            $user->syncRoles([$newRole]);

            if (! $user->is_active) {
                // I token API di un utente disattivato non devono sopravvivere
                $user->tokens()->delete();
            }
        });

        Audit::log('user.updated', $user, ['role' => $newRole, 'is_active' => $user->is_active]);

        return response()->json(['data' => $this->presented($user->refresh())]);
    }

    public function resetPassword(Request $request, string $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $temporaryPassword = $this->temporaryPassword();

        $user->forceFill(['password' => $temporaryPassword])->save();
        // Sessioni e token aperti con la vecchia password vanno chiusi
        $user->tokens()->delete();

        Audit::log('user.password_reset', $user, ['email' => $user->email]);

        return response()->json(['temporary_password' => $temporaryPassword]);
    }

    /** L'ultimo amministratore attivo non si tocca: nessuno resterebbe al timone. */
    private function guardLastAdministrator(User $user, string $newRole, bool $deactivating): void
    {
        $isAdministrator = $user->hasRole('amministratore');
        if (! $isAdministrator || (! $deactivating && $newRole === 'amministratore')) {
            return;
        }

        $otherActiveAdmins = User::query()
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $candidate) => $candidate->hasRole('amministratore'))
            ->count();

        if ($otherActiveAdmins === 0) {
            throw ValidationException::withMessages([
                'role' => 'È l\'ultimo amministratore attivo: promuovi prima qualcun altro.',
            ]);
        }
    }

    private function presented(User $user): array
    {
        $user->loadMissing('client:id,name');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'user_type' => $user->user_type,
            'is_active' => $user->is_active,
            'role' => $user->getRoleNames()->first(),
            'client' => $user->client?->only(['id', 'name']),
            'last_login_at' => $user->last_login_at,
        ];
    }

    /** Leggibile e dettabile al telefono: niente simboli ambigui. */
    private function temporaryPassword(): string
    {
        return Str::password(12, symbols: false);
    }
}
