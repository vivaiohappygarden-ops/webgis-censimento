<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Site;
use App\Support\Audit;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

class SiteController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:clients.view', only: ['index']),
            new Middleware('can:clients.manage', only: ['store', 'update', 'destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        ListQuery::validateUuidFilters($request, ['client_id']);

        $query = Site::query()->with('client:id,name')->withCount('localities');

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->string('client_id'));
        }

        return response()->json($query->orderBy('name')->paginate(ListQuery::perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        Client::findOrFail($data['client_id']);

        $site = Site::create($data);
        Audit::log('site.created', $site, ['name' => $site->name]);

        return response()->json(['data' => $site], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $site = Site::findOrFail($id);
        $data = $this->validated($request, true);
        unset($data['client_id']);

        $site->update($data);
        Audit::log('site.updated', $site);

        return response()->json(['data' => $site->fresh()]);
    }

    public function destroy(string $id): Response
    {
        $site = Site::withCount('localities')->findOrFail($id);

        if ($site->localities_count > 0) {
            throw ValidationException::withMessages([
                'site' => "La sede ha {$site->localities_count} località collegate: eliminale prima.",
            ]);
        }

        $site->delete();
        Audit::log('site.deleted', $site);

        return response()->noContent();
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'client_id' => [$updating ? 'sometimes' : 'required', 'uuid'],
            'name' => [$required, 'string', 'max:254'],
            'code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'istat_code' => ['sometimes', 'nullable', 'string', 'size:6'],
            'municipality' => ['sometimes', 'nullable', 'string', 'max:100'],
            'province' => ['sometimes', 'nullable', 'string', 'size:2'],
            'region' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);
    }
}
