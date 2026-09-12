<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioner;
use App\Support\Audit;
use App\Support\Permessi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ruoli su misura del committente.
 *
 * I cinque ruoli di serie (amministratore, tecnico, operatore, cliente,
 * impresa) restano: il programma li nomina per decidere il tipo di utente e
 * l'accesso ai portali, quindi non si rinominano e non si eliminano. Tutto
 * il resto si puo' fare: cambiare i permessi di un ruolo di serie (tranne
 * l'amministratore) e inventarne di nuovi - "capo squadra", "agronomo
 * esterno", "ufficio tecnico in sola lettura".
 *
 * Due guardie non negoziabili:
 *  - l'amministratore non si tocca: senza un ruolo con tutti i permessi ci
 *    si chiude fuori da soli;
 *  - i permessi dei portali (committente e impresa) non si mescolano con
 *    quelli interni, in nessuna direzione: sarebbero due modi diversi di
 *    far vedere a qualcuno dati che non sono suoi.
 */
class RoleAdminController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:users.manage')];
    }

    public function index(Request $request): JsonResponse
    {
        $conteggi = User::query()
            ->join('model_has_roles as mhr', function ($join) {
                $join->on('mhr.model_id', '=', 'users.id')
                    ->where('mhr.model_type', '=', User::class);
            })
            ->selectRaw('mhr.role_id, COUNT(*) AS quanti')
            ->groupBy('mhr.role_id')
            ->pluck('quanti', 'role_id');

        $ruoli = $this->ruoli($request)->with('permissions:id,name')->orderBy('name')->get()
            ->map(fn (Role $ruolo) => [
                'id' => $ruolo->id,
                'nome' => $ruolo->name,
                'permessi' => $ruolo->permissions->pluck('name')->sort()->values(),
                'utenti' => (int) ($conteggi[$ruolo->id] ?? 0),
                'di_sistema' => in_array($ruolo->name, Permessi::ruoliDiSistema(), true),
                'intoccabile' => $ruolo->name === Permessi::INTOCCABILE,
            ]);

        return response()->json([
            'data' => $ruoli,
            // Il catalogo dei permessi viaggia con l'elenco: la pagina non
            // deve conoscerli per conto suo, o al primo permesso nuovo
            // mostrerebbe una casella in meno senza dirlo
            'permessi' => Permessi::elenco(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validato($request);

        $ruolo = DB::transaction(function () use ($request, $data) {
            $ruolo = Role::create([
                'name' => $data['nome'],
                'guard_name' => 'web',
                'tenant_id' => $request->user()->tenant_id,
            ]);
            $ruolo->syncPermissions($data['permessi']);

            return $ruolo;
        });

        Audit::log('role.created', null, ['ruolo' => $ruolo->name, 'permessi' => $data['permessi']]);

        return response()->json(['data' => ['id' => $ruolo->id, 'nome' => $ruolo->name]], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $ruolo = $this->ruoli($request)->findOrFail($id);

        if ($ruolo->name === Permessi::INTOCCABILE) {
            throw ValidationException::withMessages([
                'nome' => "Il ruolo amministratore non si modifica: e' quello che tiene aperta la porta.",
            ]);
        }

        $diSistema = in_array($ruolo->name, Permessi::ruoliDiSistema(), true);
        $data = $this->validato($request, $ruolo, rinominabile: ! $diSistema);

        if ($diSistema && isset($data['nome']) && $data['nome'] !== $ruolo->name) {
            // Niente rinomine silenziose: chi ha scritto il nome nuovo deve
            // sapere che non e' stato preso
            throw ValidationException::withMessages([
                'nome' => 'I ruoli di serie non si rinominano: il programma li chiama per nome. '
                    .'Puoi cambiarne i permessi.',
            ]);
        }

        DB::transaction(function () use ($ruolo, $data, $diSistema) {
            if (! $diSistema && isset($data['nome'])) {
                $ruolo->update(['name' => $data['nome']]);
            }
            $ruolo->syncPermissions($data['permessi']);
        });

        Audit::log('role.updated', null, ['ruolo' => $ruolo->name, 'permessi' => $data['permessi']]);

        return response()->json(['data' => ['id' => $ruolo->id, 'nome' => $ruolo->fresh()->name]]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $ruolo = $this->ruoli($request)->findOrFail($id);

        if (in_array($ruolo->name, Permessi::ruoliDiSistema(), true)) {
            throw ValidationException::withMessages([
                'nome' => 'I ruoli di serie non si eliminano: al massimo si cambiano i loro permessi.',
            ]);
        }

        $quanti = DB::table('model_has_roles')
            ->where('role_id', $ruolo->id)
            ->where('model_type', User::class)
            ->count();

        if ($quanti > 0) {
            throw ValidationException::withMessages([
                'nome' => "Il ruolo e' assegnato a {$quanti} ".($quanti === 1 ? 'utente' : 'utenti')
                    .': prima spostali su un altro ruolo.',
            ]);
        }

        $nome = $ruolo->name;
        $ruolo->delete();
        Audit::log('role.deleted', null, ['ruolo' => $nome]);

        return response()->json(['data' => ['nome' => $nome]]);
    }

    /**
     * I ruoli del tenant di chi sta chiedendo.
     *
     * Il modello Role viene dal pacchetto dei permessi e NON ha il filtro
     * automatico per organizzazione (TenantScope): il pacchetto lo usa anche
     * fuori da una richiesta, quando di tenant non ce n'e' uno. Qui si filtra
     * a mano, sempre: senza, un amministratore vedrebbe - e potrebbe
     * cambiare - i ruoli di un altro studio.
     */
    private function ruoli(Request $request)
    {
        return Role::query()->where('tenant_id', $request->user()->tenant_id);
    }

    /**
     * @return array{nome?: string, permessi: list<string>}
     */
    private function validato(Request $request, ?Role $esistente = null, bool $rinominabile = true): array
    {
        $data = $request->validate([
            'nome' => [
                $esistente && ! $rinominabile ? 'sometimes' : ($esistente ? 'sometimes' : 'required'),
                'string', 'min:3', 'max:50',
                // Lettere, cifre, spazi e trattini: il nome del ruolo finisce
                // in tabelle e messaggi, non deve poter contenere marcatori
                'regex:/^[\pL\pN][\pL\pN \-\']*$/u',
                Rule::unique('roles', 'name')
                    ->where(fn ($q) => $q->where('tenant_id', $request->user()->tenant_id))
                    ->ignore($esistente?->id),
            ],
            'permessi' => ['required', 'array'],
            'permessi.*' => [Rule::in(TenantProvisioner::PERMISSIONS)],
        ]);

        $permessi = array_values(array_unique($data['permessi']));

        // Un nome che coincide con un ruolo di serie confonderebbe le
        // guardie del programma (utenti dei portali, ultimo amministratore)
        if (isset($data['nome']) && $esistente === null
            && in_array(mb_strtolower($data['nome']), Permessi::ruoliDiSistema(), true)) {
            throw ValidationException::withMessages([
                'nome' => 'Questo nome e\' gia\' di un ruolo di serie: scegline un altro.',
            ]);
        }

        $daPortale = array_intersect($permessi, Permessi::SOLO_PORTALI);
        $interni = array_diff($permessi, Permessi::SOLO_PORTALI);

        if ($daPortale !== [] && $interni !== []) {
            throw ValidationException::withMessages([
                'permessi' => 'I permessi dei portali esterni non si mescolano con quelli del gestionale: '
                    .'un ruolo e\' o di studio o di portale.',
            ]);
        }

        return ['nome' => $data['nome'] ?? null, 'permessi' => $permessi];
    }
}
