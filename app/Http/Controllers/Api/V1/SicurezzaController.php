<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Sicurezza\DueFattori;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * La regola dell'organizzazione sulla verifica in due passaggi: chi deve
 * averla (nessuno, gli amministratori, tutti). La decide chi gestisce gli
 * utenti; chi ne e' obbligato e non l'ha accesa trova solo la pagina del
 * proprio accesso finche' non la attiva (RichiediDueFattori).
 */
class SicurezzaController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:users.manage')];
    }

    public function regola(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $attivi = User::query()->where('is_active', true)
            ->where('email', 'not like', '%@'.\App\Services\Piattaforma\ConsolePiattaforma::DOMINIO_ASSISTENZA);

        return response()->json(['data' => [
            'regola' => DueFattori::regola($tenantId),
            'regole' => DueFattori::REGOLE,
            'utenti_attivi' => (clone $attivi)->count(),
            'con_verifica' => (clone $attivi)->where('mfa_enabled', true)->count(),
            // Quanti resterebbero da convincere con ciascuna regola
            'scoperti' => [
                'amministratori' => DueFattori::utentiScoperti($tenantId, 'amministratori'),
                'tutti' => DueFattori::utentiScoperti($tenantId, 'tutti'),
            ],
        ]]);
    }

    public function aggiornaRegola(Request $request): JsonResponse
    {
        $data = $request->validate(['regola' => ['required', Rule::in(DueFattori::REGOLE)]]);

        // Sotto lock e rileggendo dentro la transazione: nella stessa colonna
        // "settings" vivono il contatore dei protocolli delle perizie e le
        // altre impostazioni; salvare su una copia letta prima le riscriverebbe
        $organization = DB::transaction(function () use ($request, $data) {
            $organization = Organization::query()->lockForUpdate()->findOrFail($request->user()->tenant_id);
            $settings = $organization->settings ?? [];
            $prima = DueFattori::regola($organization);
            $settings['sicurezza'] = array_replace($settings['sicurezza'] ?? [], ['due_fattori' => $data['regola']]);
            $organization->forceFill(['settings' => $settings])->save();
            Audit::log('sicurezza.due_fattori', $organization, ['da' => $prima, 'a' => $data['regola']]);

            return $organization;
        });

        return response()->json(['data' => ['regola' => DueFattori::regola($organization)]]);
    }
}
