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
                ->with(['leader:id,name', 'members:id,name'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $team = DB::transaction(function () use ($data, $request) {
            $team = Team::create([
                'tenant_id' => $request->user()->tenant_id,
                'code' => $data['code'] ?? null,
                'name' => $data['name'],
                'leader_id' => $data['leader_id'] ?? null,
                'is_external' => (bool) ($data['is_external'] ?? false),
            ]);
            $this->syncMembers($team, $data);

            return $team;
        });

        Audit::log('team.created', $team, ['name' => $team->name]);

        return response()->json(['data' => $team->load(['leader:id,name', 'members:id,name'])], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $team = Team::query()->findOrFail($id);
        $data = $this->validated($request, required: false);

        DB::transaction(function () use ($team, $data) {
            $team->update(array_intersect_key($data, array_flip(['code', 'name', 'leader_id', 'is_active', 'is_external'])));
            $this->syncMembers($team, $data);
        });

        Audit::log('team.updated', $team);

        return response()->json(['data' => $team->fresh()->load(['leader:id,name', 'members:id,name'])]);
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
            // ordini dal portale dedicato
            'is_external' => ['sometimes', 'boolean'],
            'member_ids' => ['sometimes', 'array', 'max:50'],
            'member_ids.*' => ['uuid'],
        ]);

        if (! empty($data['leader_id'])) {
            User::query()->findOrFail($data['leader_id']);
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
