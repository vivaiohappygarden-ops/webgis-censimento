<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Support\Audit;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:clients.view', only: ['index', 'show']),
            new Middleware('can:clients.manage', only: ['store', 'update', 'destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = Client::query()->withCount('sites');

        if ($request->filled('q')) {
            $q = '%'.$request->string('q').'%';
            $query->where(fn ($w) => $w->where('name', 'ilike', $q)->orWhere('code', 'ilike', $q));
        }

        return response()->json(
            $query->orderBy('name')->paginate(ListQuery::perPage($request))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $client = Client::create($this->validated($request));
        Audit::log('client.created', $client, ['name' => $client->name]);

        return response()->json(['data' => $client], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json([
            'data' => Client::query()
                ->with(['sites' => fn ($q) => $q->withCount('localities')->orderBy('name')])
                ->withCount('contracts')
                ->findOrFail($id),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        $client->update($this->validated($request, $client));
        Audit::log('client.updated', $client);

        return response()->json(['data' => $client->fresh()]);
    }

    public function destroy(string $id): Response
    {
        $client = Client::withCount('sites')->findOrFail($id);

        if ($client->sites_count > 0) {
            throw ValidationException::withMessages([
                'client' => "Il cliente ha {$client->sites_count} sedi collegate: eliminale prima.",
            ]);
        }

        $portalUsers = \App\Models\User::query()->where('client_id', $client->id)->count();
        if ($portalUsers > 0) {
            throw ValidationException::withMessages([
                'client' => "Il cliente ha {$portalUsers} utenti del portale collegati: disattivali o spostali prima.",
            ]);
        }

        $client->delete();
        Audit::log('client.deleted', $client);

        return response()->noContent();
    }

    private function validated(Request $request, ?Client $existing = null): array
    {
        $required = $existing ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:254'],
            'code' => ['sometimes', 'nullable', 'string', 'max:40',
                Rule::unique('clients', 'code')->where(fn ($q) => $q
                    ->where('tenant_id', $request->user()->tenant_id)->whereNull('deleted_at'))
                    ->ignore($existing?->id),
            ],
            'client_type' => ['sometimes', 'in:public,private,condo,other'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'fiscal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'pec' => ['sometimes', 'nullable', 'email'],
            'sdi_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'ipa_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'address' => ['sometimes', 'array'],
            'contacts' => ['sometimes', 'array'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
