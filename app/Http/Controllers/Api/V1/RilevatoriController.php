<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * I rilevatori abilitati dell'organizzazione: agronomi esterni e operatori
 * qualificati che possono eseguire il rilievo di una valutazione di
 * stabilita'. Stanno in organizations.settings['rilevatori'] e si regolano da
 * Utenti, sotto "Chi firma"; la scheda VTA li propone nella voce "Rilievo
 * eseguito da" e conserva nella valutazione una copia dei dati (titolo, albo,
 * partita IVA), cosi' la perizia stampa quello che valeva quel giorno anche
 * se l'elenco cambia.
 */
class RilevatoriController extends Controller implements HasMiddleware
{
    public const CAMPI = ['nome', 'titolo', 'iscrizione', 'partita_iva'];

    public static function middleware(): array
    {
        return [new Middleware('can:users.manage', only: ['update'])];
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => self::elenco(Organization::query()->findOrFail($request->user()->tenant_id))]);
    }

    /** Sostituisce l'elenco intero: e' corto e si modifica da una pagina sola. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rilevatori' => ['present', 'array', 'max:100'],
            'rilevatori.*.id' => ['nullable', 'string', 'max:40'],
            'rilevatori.*.nome' => ['required', 'string', 'max:150'],
            'rilevatori.*.titolo' => ['nullable', 'string', 'max:150'],
            'rilevatori.*.iscrizione' => ['nullable', 'string', 'max:200'],
            'rilevatori.*.partita_iva' => ['nullable', 'string', 'max:30'],
            'rilevatori.*.user_id' => ['nullable', 'uuid'],
        ]);

        // Sotto lock come le altre impostazioni: nella stessa colonna vivono
        // il contatore dei protocolli e le altre regolazioni
        $elenco = DB::transaction(function () use ($request, $data) {
            $organizzazione = Organization::query()->lockForUpdate()->findOrFail($request->user()->tenant_id);
            $utenti = User::query()->pluck('id')->map(fn ($id) => (string) $id)->all();
            $righe = [];
            foreach ($data['rilevatori'] as $r) {
                $riga = ['id' => filled($r['id'] ?? null) ? (string) $r['id'] : (string) Str::uuid()];
                foreach (self::CAMPI as $campo) {
                    $valore = trim((string) ($r[$campo] ?? ''));
                    $riga[$campo] = $valore !== '' ? $valore : null;
                }
                $riga['user_id'] = ! empty($r['user_id']) && in_array((string) $r['user_id'], $utenti, true) ? (string) $r['user_id'] : null;
                $righe[] = $riga;
            }
            $settings = $organizzazione->settings ?? [];
            $settings['rilevatori'] = $righe;
            $organizzazione->forceFill(['settings' => $settings])->save();

            return $righe;
        });

        Audit::log('rilevatori.aggiornati', null, ['quanti' => count($elenco), 'nomi' => array_column($elenco, 'nome')]);

        return response()->json(['data' => $elenco]);
    }

    /** @return list<array{id: string, nome: string, titolo: ?string, iscrizione: ?string, partita_iva: ?string, user_id: ?string}> */
    public static function elenco(Organization $organizzazione): array
    {
        return array_values($organizzazione->settings['rilevatori'] ?? []);
    }
}
