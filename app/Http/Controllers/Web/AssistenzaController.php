<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Middleware\InvalidateStaleSessions;
use App\Models\Organization;
use App\Models\User;
use App\Services\Piattaforma\ConsolePiattaforma;
use App\Services\Sicurezza\DueFattori;
use App\Support\HomeRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * L'accesso di assistenza dalla console: il gestore entra in un'altra
 * organizzazione come "Assistenza piattaforma" (un utente a se', non un
 * travestimento: nel registro dell'organizzazione ogni azione porta quel
 * nome) e, finito, torna al proprio utente. La sessione ricorda chi era.
 */
class AssistenzaController extends Controller
{
    public function inizia(Request $request, string $organization, ConsolePiattaforma $console): RedirectResponse
    {
        $gestore = $request->user();
        abort_unless(DueFattori::attiva($gestore), 403, 'Per la console della piattaforma serve la verifica in due passaggi attiva.');

        $organizzazione = Organization::query()->findOrFail($organization);
        if (! $organizzazione->is_active) {
            throw ValidationException::withMessages(['organizzazione' => 'L\'organizzazione è sospesa: riattivala prima di entrare in assistenza.']);
        }
        if ($organizzazione->id === $gestore->tenant_id) {
            throw ValidationException::withMessages(['organizzazione' => 'Sei già nella tua organizzazione.']);
        }

        $utente = $console->iniziaAssistenza($organizzazione, $gestore);

        Auth::guard('web')->login($utente);
        $request->session()->regenerate();
        $request->session()->put('assistenza', [
            'gestore' => $gestore->id,
            'utente' => $utente->id,
            'organizzazione' => $organizzazione->name,
            'inizio' => now()->toIso8601String(),
        ]);
        InvalidateStaleSessions::ricorda($request, $utente);
        app(PermissionRegistrar::class)->setPermissionsTeamId($utente->tenant_id);

        return redirect()->route(HomeRoute::for($utente));
    }

    public function termina(Request $request, ConsolePiattaforma $console): RedirectResponse
    {
        $marker = $request->session()->get('assistenza');
        $utente = $request->user();
        abort_unless(is_array($marker) && $utente && $utente->id === ($marker['utente'] ?? null), 403);

        $gestore = User::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where('is_active', true)->where('is_platform_manager', true)->find($marker['gestore'] ?? '');

        $console->terminaAssistenza($utente, $gestore);
        $request->session()->forget('assistenza');

        if (! $gestore) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        Auth::guard('web')->login($gestore);
        $request->session()->regenerate();
        InvalidateStaleSessions::ricorda($request, $gestore);
        app(PermissionRegistrar::class)->setPermissionsTeamId($gestore->tenant_id);

        return redirect()->route('piattaforma');
    }
}
