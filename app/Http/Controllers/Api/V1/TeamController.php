<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class TeamController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:works.view', only: ['index']),
            new Middleware('can:works.manage', only: ['store', 'update']),
        ];
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Team::query()
                ->with(['leader:id,name', 'members:id,name', 'client:id,name'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $esterna = (bool) ($data['is_external'] ?? false);
        $this->assertCommittentePerImpresa($esterna, $data['client_id'] ?? null);

        $team = DB::transaction(function () use ($data, $request, $esterna) {
            $team = Team::create([
                'tenant_id' => $request->user()->tenant_id,
                'code' => $data['code'] ?? null,
                'name' => $data['name'],
                'leader_id' => $data['leader_id'] ?? null,
                'is_external' => $esterna,
                // Il committente ha senso solo per le imprese esterne
                'client_id' => $esterna ? ($data['client_id'] ?? null) : null,
            ]);
            $this->syncMembers($team, $data);

            return $team;
        });

        Audit::log('team.created', $team, ['name' => $team->name]);

        return response()->json(['data' => $team->load(['leader:id,name', 'members:id,name', 'client:id,name'])], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $team = Team::query()->findOrFail($id);
        $data = $this->validated($request, required: false);

        // La regola "un'impresa appartiene a un committente" si controlla
        // quando la richiesta tocca proprio questi campi: cosi' una squadra
        // esterna d'epoca (senza committente) resta aggiornabile nei membri,
        // ma appena la si tocca va collegata
        if (array_key_exists('is_external', $data) || array_key_exists('client_id', $data)) {
            $esterna = (bool) ($data['is_external'] ?? $team->is_external);
            $committente = array_key_exists('client_id', $data) ? $data['client_id'] : $team->client_id;
            $this->assertCommittentePerImpresa($esterna, $committente);
            $data['client_id'] = $esterna ? $committente : null;
            $data['is_external'] = $esterna;
        }

        DB::transaction(function () use ($team, $data) {
            $team->update(array_intersect_key($data, array_flip(['code', 'name', 'leader_id', 'is_active', 'is_external', 'client_id'])));
            $this->syncMembers($team, $data);
        });

        Audit::log('team.updated', $team);

        return response()->json(['data' => $team->fresh()->load(['leader:id,name', 'members:id,name', 'client:id,name'])]);
    }

    /** Un'impresa esterna appartiene a un committente: senza, non si registra. */
    private function assertCommittentePerImpresa(bool $esterna, ?string $clientId): void
    {
        if ($esterna && empty($clientId)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'client_id' => 'Un\'impresa esterna appartiene a un committente: sceglilo.',
            ]);
        }
    }

    private function validated(Request $request, bool $required = true): array
    {
        $presence = $required ? 'required' : 'sometimes';

        $data = $request->validate([
            'name' => [$presence, 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:30'],
            'leader_id' => ['nullable', 'uuid'],
            'is_active' => ['sometimes', 'boolean'],
            // Impresa esterna: i membri (ruolo "impresa") vedono i suoi
            // ordini dal portale dedicato. Appartiene a UN committente
            'is_external' => ['sometimes', 'boolean'],
            'client_id' => ['sometimes', 'nullable', 'uuid'],
            'member_ids' => ['sometimes', 'array', 'max:50'],
            'member_ids.*' => ['uuid'],
        ]);

        if (! empty($data['leader_id'])) {
            User::query()->findOrFail($data['leader_id']);
        }
        if (! empty($data['client_id'])) {
            // Deve essere un committente del tenant (404 se estraneo)
            \App\Models\Client::query()->findOrFail($data['client_id']);
        }

        return $data;
    }

    private function syncMembers(Team $team, array $data): void
    {
        if (! array_key_exists('member_ids', $data)) {
            return;
        }
        // Solo utenti del tenant: gli id estranei vengono scartati dallo scope
        $valid = User::query()->whereIn('id', $data['member_ids'])->pluck('id');
        $team->members()->sync(
            $valid->mapWithKeys(fn ($id) => [$id => ['tenant_id' => $team->tenant_id]])->all(),
        );
    }
}
