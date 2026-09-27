<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Sicurezza\DueFattori;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Il proprio accesso: la verifica in due passaggi e la password del proprio
 * utente. Ogni operazione delicata chiede di nuovo la password: una sessione
 * lasciata aperta non basta a spegnere la verifica o a cambiare la chiave.
 */
class ProfiloController extends Controller
{
    /** Lo stato dell'accesso, per la pagina "Il mio accesso". */
    public function sicurezza(Request $request): JsonResponse
    {
        $user = $request->user();
        $regola = DueFattori::regola($user->tenant_id);

        return response()->json(['data' => [
            'due_fattori' => [
                'attiva' => DueFattori::attiva($user),
                'attiva_dal' => $user->mfa_confirmed_at?->toIso8601String(),
                'codici_recupero' => DueFattori::codiciRimasti($user),
                'obbligatoria' => DueFattori::obbligatoriaPer($user, $regola),
                'in_attesa' => DueFattori::inAttesa($user),
            ],
            'regola' => $regola,
            'ultimo_accesso' => $user->last_login_at?->toIso8601String(),
        ]]);
    }

    public function avviaDueFattori(Request $request): JsonResponse
    {
        $user = $this->conPassword($request);
        if (DueFattori::attiva($user)) {
            throw ValidationException::withMessages([
                'password' => 'La verifica è già attiva: per passare a un altro telefono prima disattivala.',
            ]);
        }

        return response()->json(['data' => DueFattori::avvia($user)]);
    }

    public function confermaDueFattori(Request $request): JsonResponse
    {
        $data = $request->validate(['codice' => ['required', 'string', 'max:12']]);
        $user = $request->user();

        $codici = DueFattori::conferma($user, $data['codice']);
        if ($codici === null) {
            throw ValidationException::withMessages([
                'codice' => 'Codice non valido. Controlla che l\'ora del telefono sia giusta e riprova con il codice nuovo.',
            ]);
        }
        Audit::log('mfa.enabled', $user);

        return response()->json(['data' => ['codici_recupero' => $codici]]);
    }

    public function nuoviCodiciRecupero(Request $request): JsonResponse
    {
        $user = $this->conPassword($request);
        if (! DueFattori::attiva($user)) {
            throw ValidationException::withMessages(['password' => 'La verifica in due passaggi non è attiva.']);
        }
        $codici = DueFattori::nuoviCodici($user);
        Audit::log('mfa.recovery_codes', $user);

        return response()->json(['data' => ['codici_recupero' => $codici]]);
    }

    public function disattivaDueFattori(Request $request): JsonResponse
    {
        $user = $this->conPassword($request);
        if (DueFattori::obbligatoriaPer($user)) {
            throw ValidationException::withMessages([
                'password' => 'La tua organizzazione richiede la verifica in due passaggi: non si può spegnere.',
            ]);
        }
        DueFattori::spegni($user);
        Audit::log('mfa.disabled', $user);

        return response()->json(['data' => ['attiva' => false]]);
    }

    public function cambiaPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password_attuale' => ['required', 'string'],
            'password' => ['required', 'string', 'min:10', 'max:200', 'confirmed', 'different:password_attuale'],
        ], [
            'password.min' => 'La nuova password deve avere almeno 10 caratteri.',
            'password.confirmed' => 'Le due password nuove non coincidono.',
            'password.different' => 'La nuova password deve essere diversa da quella attuale.',
        ]);

        $user = $request->user();
        if (! Hash::check($data['password_attuale'], $user->password)) {
            throw ValidationException::withMessages(['password_attuale' => 'La password attuale non è giusta.']);
        }

        // Le altre sessioni (InvalidateStaleSessions confronta l'hash a ogni
        // richiesta), i gettoni API e il cookie "ricordami" aperti con la
        // vecchia password decadono; questa sessione prosegue
        $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
        $attuale = $user->currentAccessToken();
        $user->tokens()
            ->when($attuale instanceof PersonalAccessToken, fn ($q) => $q->where('id', '!=', $attuale->id))
            ->delete();
        if ($request->hasSession()) {
            $guard = Auth::guard('web');
            $hash = (string) $user->getAuthPassword();
            $request->session()->put('password_hash_web', method_exists($guard, 'hashPasswordForCookie') ? $guard->hashPasswordForCookie($hash) : $hash);
        }
        Audit::log('auth.password_changed', $user);

        return response()->json(['data' => ['ok' => true]]);
    }

    /** Le operazioni delicate chiedono di nuovo la password. */
    private function conPassword(Request $request): User
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();
        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'La password non è giusta.']);
        }

        return $user;
    }
}
