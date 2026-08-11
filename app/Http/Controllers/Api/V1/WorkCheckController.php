<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NonConformity;
use App\Models\WorkCheck;
use App\Models\WorkOrder;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Verbali di controllo qualità su un ordine di lavoro. L'ordine controllato
 * non cambia mai stato: un esito negativo apre una non conformità e, a
 * scelta, genera un nuovo ordine correttivo (origin: non_conformity).
 */
class WorkCheckController extends Controller implements HasMiddleware
{
    /** Il controllo ha senso solo su lavoro reale, in corso o concluso. */
    private const CHECKABLE_STATUSES = ['in_progress', 'suspended', 'completed'];

    public static function middleware(): array
    {
        return [new Middleware('can:works.manage')];
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['passed', 'failed'])],
            'notes' => ['nullable', 'string'],
            'non_conformity' => ['required_if:outcome,failed', 'array'],
            'non_conformity.severity' => ['required_with:non_conformity', Rule::in(['minor', 'major', 'critical'])],
            'non_conformity.description' => ['required_with:non_conformity', 'string', 'max:2000'],
            'non_conformity.due_on' => ['nullable', 'date', 'after_or_equal:today'],
            'non_conformity.responsible_id' => ['nullable', 'uuid'],
            'non_conformity.asset_id' => ['nullable', 'uuid'],
            'non_conformity.create_corrective_order' => ['sometimes', 'boolean'],
        ], [
            'non_conformity.required_if' => 'Un esito negativo richiede la registrazione della non conformità.',
        ]);

        $user = $request->user();
        $workOrder = WorkOrder::query()->with('assets')->findOrFail($id);

        if (! in_array($workOrder->status, self::CHECKABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'work_order' => 'Il controllo qualità si registra su lavori in corso, sospesi o completati.',
            ]);
        }

        // I riferimenti della non conformità devono appartenere al tenant
        if (! empty($data['non_conformity']['responsible_id'])) {
            \App\Models\User::query()->findOrFail($data['non_conformity']['responsible_id']);
        }
        if (! empty($data['non_conformity']['asset_id'])) {
            \App\Models\Asset::query()->findOrFail($data['non_conformity']['asset_id']);
        }

        DB::transaction(function () use ($data, $user, $workOrder) {
            $check = WorkCheck::create([
                'tenant_id' => $user->tenant_id,
                'work_order_id' => $workOrder->id,
                'checked_by' => $user->id,
                'checked_at' => now(),
                'outcome' => $data['outcome'],
                'notes' => $data['notes'] ?? null,
            ]);

            Audit::log('work_check.created', $check, ['outcome' => $check->outcome, 'work_order_id' => $workOrder->id]);

            if ($data['outcome'] !== 'failed') {
                return;
            }

            $ncData = $data['non_conformity'];
            $nonConformity = NonConformity::create([
                'tenant_id' => $user->tenant_id,
                'code' => $this->nextNcCode($user->tenant_id),
                'origin' => 'work_check',
                'origin_id' => $check->id,
                'severity' => $ncData['severity'],
                'status' => 'open',
                'asset_id' => $ncData['asset_id'] ?? null,
                'work_order_id' => $workOrder->id,
                'description' => $ncData['description'],
                'responsible_id' => $ncData['responsible_id'] ?? null,
                'due_on' => $ncData['due_on'] ?? null,
                'created_by' => $user->id,
            ]);

            Audit::log('non_conformity.created', $nonConformity, ['code' => $nonConformity->code]);

            if (! ($ncData['create_corrective_order'] ?? false)) {
                return;
            }

            // L'ordine controllato resta com'è (gli stati terminali sono
            // immutabili): la ripresa del lavoro è un NUOVO ordine correttivo
            $corrective = WorkOrder::create([
                'tenant_id' => $user->tenant_id,
                'code' => WorkOrder::nextCode($user->tenant_id),
                'title' => "Azione correttiva {$nonConformity->code}: {$workOrder->title}",
                'description' => $ncData['description'],
                'status' => 'draft',
                'priority' => $ncData['severity'] === 'critical' ? 'urgent' : 'high',
                'origin' => 'non_conformity',
                'origin_id' => $nonConformity->id,
                'client_id' => $workOrder->client_id,
                'area_id' => $workOrder->area_id,
                'work_type_id' => $workOrder->work_type_id,
                'team_id' => $workOrder->team_id,
                'price_list_id' => $workOrder->price_list_id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            // Gli elementi da rilavorare sono quelli dell'ordine controllato
            // (o il solo elemento indicato nella non conformità)
            $rows = $workOrder->assets
                ->when(! empty($ncData['asset_id']), fn ($c) => $c->where('asset_id', $ncData['asset_id']));
            foreach ($rows as $row) {
                \App\Models\WorkOrderAsset::create([
                    'tenant_id' => $user->tenant_id,
                    'work_order_id' => $corrective->id,
                    'asset_id' => $row->asset_id,
                    'work_type_id' => $row->work_type_id,
                    'planned_quantity' => $row->planned_quantity,
                    'unit' => $row->unit,
                ]);
            }

            Audit::log('work_order.created', $corrective, ['code' => $corrective->code, 'origin' => 'non_conformity']);
        });

        return app(WorkOrderController::class)->show($id);
    }

    /** Numerazione per tenant: NC-ANNO-progressivo, serializzata con advisory lock. */
    private function nextNcCode(string $tenantId): string
    {
        DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 43))', ["nc:{$tenantId}"]);
        $year = now()->year;
        $next = (int) DB::selectOne(
            "SELECT COALESCE(MAX((substring(code FROM '\\d+$'))::int), 0) + 1 AS n
             FROM non_conformities WHERE tenant_id = ? AND code LIKE ?",
            [$tenantId, "NC-{$year}-%"],
        )->n;

        return sprintf('NC-%d-%04d', $year, $next);
    }
}
