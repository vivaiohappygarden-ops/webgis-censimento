<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NonConformity;
use App\Support\Audit;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NonConformityController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:works.view', only: ['index']),
            new Middleware('can:works.manage', only: ['update']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(NonConformity::STATUSES)],
            'severity' => ['nullable', Rule::in(['minor', 'major', 'critical'])],
        ]);

        $query = NonConformity::query()
            ->with([
                'workOrder:id,code,title,status',
                'asset:id,census_code',
                'responsible:id,name',
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->string('severity'));
        }

        // Le aperte prima, per scadenza; le chiuse in coda per data recente
        return response()->json(
            $query->orderByRaw("CASE WHEN status = 'closed' THEN 1 ELSE 0 END")
                ->orderByRaw('due_on NULLS LAST')
                ->orderByDesc('created_at')
                ->paginate(ListQuery::perPage($request, 25, 100))
        );
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(NonConformity::STATUSES)],
            'severity' => ['sometimes', Rule::in(['minor', 'major', 'critical'])],
            'root_cause' => ['nullable', 'string', 'max:2000'],
            'corrective_action' => ['nullable', 'string', 'max:2000'],
            'due_on' => ['nullable', 'date'],
            'responsible_id' => ['nullable', 'uuid'],
        ]);

        if (! empty($data['responsible_id'])) {
            \App\Models\User::query()->findOrFail($data['responsible_id']);
        }

        $nonConformity = DB::transaction(function () use ($request, $id, $data) {
            $nonConformity = NonConformity::query()->lockForUpdate()->findOrFail($id);

            // Una non conformità chiusa è storia: non si riscrive
            if ($nonConformity->status === 'closed') {
                throw ValidationException::withMessages([
                    'non_conformity' => 'Una non conformità chiusa non è modificabile.',
                ]);
            }

            if (isset($data['status'])) {
                if (! $nonConformity->canTransitionTo($data['status'])) {
                    throw ValidationException::withMessages([
                        'status' => "Passaggio non ammesso: da '{$nonConformity->status}' a '{$data['status']}'.",
                    ]);
                }
                if ($data['status'] === 'closed') {
                    $nonConformity->closed_at = now();
                }
            }

            $nonConformity->fill($data);
            $nonConformity->save();

            Audit::log('non_conformity.updated', $nonConformity, ['status' => $nonConformity->status]);

            return $nonConformity;
        });

        return response()->json([
            'data' => [
                ...$nonConformity->load(['workOrder:id,code,title,status', 'asset:id,census_code', 'responsible:id,name'])->toArray(),
                'allowed_transitions' => NonConformity::TRANSITIONS[$nonConformity->status] ?? [],
            ],
        ]);
    }
}
