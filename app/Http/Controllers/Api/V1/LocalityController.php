<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Locality;
use App\Models\Site;
use App\Support\Audit;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

class LocalityController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:areas.view', only: ['index']),
            new Middleware('can:clients.manage', only: ['store', 'update', 'destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        ListQuery::validateUuidFilters($request, ['site_id']);

        $query = Locality::query()->with('site:id,name,client_id')->withCount('areas');

        if ($request->filled('site_id')) {
            $query->where('site_id', $request->string('site_id'));
        }

        return response()->json($query->orderBy('name')->paginate(ListQuery::perPage($request, 50, 200)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        Site::findOrFail($data['site_id']);

        $locality = Locality::create($data);
        Audit::log('locality.created', $locality, ['name' => $locality->name]);

        return response()->json(['data' => $locality], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $locality = Locality::findOrFail($id);
        $data = $this->validated($request, true);
        unset($data['site_id']);

        $locality->update($data);
        Audit::log('locality.updated', $locality);

        return response()->json(['data' => $locality->fresh()]);
    }

    public function destroy(string $id): Response
    {
        $locality = Locality::withCount('areas')->findOrFail($id);

        if ($locality->areas_count > 0) {
            throw ValidationException::withMessages([
                'locality' => "La località ha {$locality->areas_count} aree collegate: eliminale prima.",
            ]);
        }

        $locality->delete();
        Audit::log('locality.deleted', $locality);

        return response()->noContent();
    }

    private function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'site_id' => [$updating ? 'sometimes' : 'required', 'uuid'],
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:254'],
            'code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'survey_zone_code' => ['sometimes', 'nullable', 'string', 'max:12'],
        ]);
    }
}
