<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Models\Zone;
use App\Services\Piattaforma\ConsolePiattaforma;
use App\Support\Audit;
use App\Support\PerimetroZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Le zone dell'organizzazione (punto 4 del committente, 04/10/2026): nome,
 * committenti che vi stanno e utenti che vi sono assegnati. Le gestisce solo
 * la sede centrale (permesso users.manage e nessuna zona addosso): un
 * responsabile di zona non puo' allargarsi il perimetro da solo.
 */
class ZoneController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:users.manage')];
    }

    public function index(Request $request): JsonResponse
    {
        PerimetroZone::autorizzaCentrale($request->user());

        $zone = Zone::query()->with(['clients:id,name,code', 'users:id,name,email'])->orderBy('name')->get();
        $utenti = User::query()->where('email', 'not like', '%@'.ConsolePiattaforma::DOMINIO_ASSISTENZA)
            ->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email', 'user_type']);
        $assegnati = DB::table('zone_user')->join('zones', 'zones.id', '=', 'zone_user.zone_id')
            ->where('zones.tenant_id', $request->user()->tenant_id)->pluck('zone_user.zone_id', 'zone_user.user_id')
            ->groupBy(fn ($zoneId, $userId) => $userId);

        return response()->json(['data' => [
            'zone' => $zone->map(fn (Zone $z) => $this->presenta($z))->values(),
            'committenti' => Client::query()->orderBy('name')->get(['id', 'name', 'code'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code]),
            'utenti' => $utenti->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'ruolo' => $u->getRoleNames()->first()]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        PerimetroZone::autorizzaCentrale($request->user());
        $data = $this->validata($request);

        $zona = DB::transaction(function () use ($request, $data) {
            $zona = Zone::create([
                'tenant_id' => $request->user()->tenant_id,
                'name' => $data['name'], 'code' => $data['code'] ?? null, 'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            $this->assegna($zona, $data);
            Audit::log('zone.created', $zona, ['name' => $zona->name]);

            return $zona;
        });
        PerimetroZone::azzera();

        return response()->json(['data' => $this->presenta($zona->load(['clients:id,name,code', 'users:id,name,email']))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        PerimetroZone::autorizzaCentrale($request->user());
        $data = $this->validata($request, $id);

        $zona = DB::transaction(function () use ($request, $id, $data) {
            $zona = Zone::query()->lockForUpdate()->findOrFail($id);
            $zona->fill(collect($data)->only(['name', 'code', 'notes'])->all());
            $zona->updated_by = $request->user()->id;
            $zona->save();
            $this->assegna($zona, $data);
            Audit::log('zone.updated', $zona, ['name' => $zona->name, 'committenti' => count($data['client_ids'] ?? []), 'utenti' => count($data['user_ids'] ?? [])]);

            return $zona;
        });
        PerimetroZone::azzera();

        return response()->json(['data' => $this->presenta($zona->load(['clients:id,name,code', 'users:id,name,email']))]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        PerimetroZone::autorizzaCentrale($request->user());
        $zona = Zone::query()->withCount('users')->findOrFail($id);
        // Togliere la zona a chi ci lavora lo lascerebbe senza territorio (o,
        // peggio, centrale): prima si spostano gli utenti, poi si elimina
        if ($zona->users_count > 0) {
            throw ValidationException::withMessages(['zona' => "La zona ha ancora {$zona->users_count} ".($zona->users_count === 1 ? 'utente assegnato' : 'utenti assegnati').': spostali prima di eliminarla.']);
        }
        Audit::log('zone.deleted', $zona, ['name' => $zona->name]);
        $zona->delete();
        PerimetroZone::azzera();

        return response()->json(['data' => ['ok' => true]]);
    }

    /** @return array<string, mixed> */
    private function validata(Request $request, ?string $id = null): array
    {
        $tenantId = $request->user()->tenant_id;

        return $request->validate([
            'name' => [$id ? 'sometimes' : 'required', 'string', 'max:150',
                Rule::unique('zones', 'name')->where('tenant_id', $tenantId)->ignore($id)],
            'code' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'client_ids' => ['sometimes', 'array', 'max:500'],
            'client_ids.*' => ['uuid', Rule::exists('clients', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'user_ids' => ['sometimes', 'array', 'max:500'],
            'user_ids.*' => ['uuid', Rule::exists('users', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
        ], [
            'name.unique' => 'Esiste già una zona con questo nome.',
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function assegna(Zone $zona, array $data): void
    {
        if (array_key_exists('client_ids', $data)) {
            $zona->clients()->sync(array_values(array_unique($data['client_ids'])));
        }
        if (array_key_exists('user_ids', $data)) {
            // Chi gestisce le zone resta centrale: assegnarsi a una zona lo chiuderebbe fuori dalla pagina
            $zona->users()->sync(array_values(array_unique($data['user_ids'])));
        }
    }

    /** @return array<string, mixed> */
    private function presenta(Zone $zona): array
    {
        return [
            'id' => $zona->id,
            'name' => $zona->name,
            'code' => $zona->code,
            'notes' => $zona->notes,
            'committenti' => $zona->clients->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code])->values(),
            'utenti' => $zona->users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->values(),
        ];
    }
}
