<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\WorkOrder;
use App\Support\Audit;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Segnalazioni: problemi riportati da colleghi o clienti su elementi e
 * aree. Flusso: aperta, presa in carico, risolta (o archiviata); da una
 * segnalazione si genera direttamente l'ordine di lavoro (origin: issue).
 */
class IssueController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            // Chi lavora sul verde può sia vedere sia aprire segnalazioni;
            // gestione e ordini restano a chi coordina
            new Middleware('can:works.view', only: ['index', 'store']),
            new Middleware('can:works.manage', only: ['update', 'createWorkOrder']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        ListQuery::validateUuidFilters($request, ['asset_id', 'area_id']);
        $request->validate([
            'status' => ['nullable', Rule::in(Issue::STATUSES)],
            'severity' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'sla' => ['nullable', Rule::in(['overdue', 'late'])],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        $query = Issue::query()
            ->with([
                'asset:id,census_code', 'area:id,name',
                'reporter:id,name', 'workOrder:id,code,status',
                'photos:id,subject_id,original_filename',
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->string('severity'));
        }
        foreach (['asset_id', 'area_id'] as $key) {
            if ($request->filled($key)) {
                $query->where($key, $request->string($key));
            }
        }
        if ($request->filled('q')) {
            $q = '%'.$request->string('q').'%';
            $query->where(fn ($w) => $w->where('code', 'ilike', $q)
                ->orWhere('description', 'ilike', $q)
                ->orWhere('reporter_name', 'ilike', $q));
        }
        // "Fuori tempo massimo": aperte oltre la finestra di presa in carico
        // o comunque oltre la scadenza di risoluzione
        if ($request->string('sla')->value() === 'overdue') {
            $query->whereIn('status', ['open', 'in_charge'])
                ->where(fn ($w) => $w->where('sla_due_at', '<', now())
                    ->orWhere(fn ($o) => $o->where('status', 'open')
                        ->where('taken_charge_due_at', '<', now())));
        }
        // "Gestite oltre i tempi": fasi concluse, ma dopo la loro scadenza
        if ($request->string('sla')->value() === 'late') {
            $query->where('status', '!=', 'dismissed')
                ->where(fn ($w) => $w->whereColumn('taken_charge_at', '>', 'taken_charge_due_at')
                    ->orWhereColumn('resolved_at', '>', 'sla_due_at'));
        }

        // Le aperte prima, le più gravi in alto; risolte e archiviate in coda
        return response()->json(
            $query->orderByRaw("CASE WHEN status IN ('resolved','dismissed') THEN 1 ELSE 0 END")
                ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
                ->orderByDesc('created_at')
                ->paginate(ListQuery::perPage($request, 25, 100))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:4000'],
            'severity' => ['sometimes', Rule::in(['low', 'medium', 'high', 'critical'])],
            'reporter_type' => ['sometimes', Rule::in(['internal', 'client'])],
            'reporter_name' => ['nullable', 'string', 'max:150'],
            'reporter_contact' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:100'],
            'asset_id' => ['nullable', 'uuid'],
            'area_id' => ['nullable', 'uuid'],
        ]);

        // Una segnalazione di un cliente deve dire chi l'ha fatta
        if (($data['reporter_type'] ?? 'internal') === 'client' && empty($data['reporter_name'])) {
            throw ValidationException::withMessages([
                'reporter_name' => 'Per una segnalazione del cliente indicare il nome di chi segnala.',
            ]);
        }

        if (! empty($data['asset_id'])) {
            \App\Models\Asset::query()->findOrFail($data['asset_id']);
        }
        if (! empty($data['area_id'])) {
            \App\Models\Area::query()->findOrFail($data['area_id']);
        }

        $user = $request->user();
        $issue = DB::transaction(function () use ($data, $user) {
            return Issue::create([
                ...$data,
                'tenant_id' => $user->tenant_id,
                'code' => Issue::nextCode($user->tenant_id),
                'status' => 'open',
                // Esplicita, non lasciata al DEFAULT del DB: la risposta 201
                // deve già contenere la gravità effettiva
                'severity' => $data['severity'] ?? 'medium',
                'sla_due_at' => \App\Support\IssueSla::resolveDueAt(now(), $data['severity'] ?? 'medium'),
                'taken_charge_due_at' => \App\Support\IssueSla::takeChargeDueAt(now(), $data['severity'] ?? 'medium'),
                'reporter_type' => $data['reporter_type'] ?? 'internal',
                'reporter_user_id' => $user->id,
                'channel' => 'backoffice',
            ]);
        });

        Audit::log('issue.created', $issue, ['code' => $issue->code]);

        return response()->json(['data' => $this->presented($issue)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Issue::STATUSES)],
            'severity' => ['sometimes', Rule::in(['low', 'medium', 'high', 'critical'])],
            'category' => ['nullable', 'string', 'max:100'],
            'resolution_notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $issue = DB::transaction(function () use ($request, $id, $data) {
            $issue = Issue::query()->lockForUpdate()->findOrFail($id);

            // Risolta o archiviata: la segnalazione è storia, non si riscrive
            if (in_array($issue->status, ['resolved', 'dismissed'], true)) {
                throw ValidationException::withMessages([
                    'issue' => 'Una segnalazione risolta o archiviata non è modificabile.',
                ]);
            }

            if (isset($data['status'])) {
                if (! $issue->canTransitionTo($data['status'])) {
                    throw ValidationException::withMessages([
                        'status' => "Passaggio non ammesso: da '{$issue->status}' a '{$data['status']}'.",
                    ]);
                }
                if ($data['status'] === 'in_charge') {
                    $issue->taken_charge_at = now();
                }
                // La riapertura annulla la presa in carico e le note: una
                // segnalazione tornata aperta riparte pulita
                if ($data['status'] === 'open') {
                    $issue->taken_charge_at = null;
                    $issue->resolution_notes = null;
                }
                if ($data['status'] === 'resolved') {
                    // La chiusura deve spiegare COME si è risolto
                    if (empty($data['resolution_notes']) && empty($issue->resolution_notes)) {
                        throw ValidationException::withMessages([
                            'resolution_notes' => 'Per chiudere la segnalazione descrivere come è stata risolta.',
                        ]);
                    }
                    $issue->resolved_at = now();
                }
            }

            $issue->fill($data);
            // Il cambio di gravità sposta le scadenze non ancora onorate,
            // sempre contate dall'apertura: declassare non azzera il tempo
            // già corso, e una presa in carico conclusa non si rigiudica
            if ($issue->isDirty('severity')) {
                $issue->sla_due_at = \App\Support\IssueSla::resolveDueAt($issue->created_at, $issue->severity);
                if ($issue->taken_charge_at === null) {
                    $issue->taken_charge_due_at = \App\Support\IssueSla::takeChargeDueAt($issue->created_at, $issue->severity);
                }
            }
            $issue->save();

            Audit::log('issue.updated', $issue, ['status' => $issue->status]);

            return $issue;
        });

        return response()->json(['data' => $this->presented($issue)]);
    }

    /** Genera l'ordine di lavoro dalla segnalazione (una sola volta). */
    public function createWorkOrder(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $issue = DB::transaction(function () use ($id, $user) {
            $issue = Issue::query()->lockForUpdate()->findOrFail($id);

            if ($issue->work_order_id !== null) {
                throw ValidationException::withMessages([
                    'issue' => 'Questa segnalazione ha già generato un ordine di lavoro.',
                ]);
            }
            if (in_array($issue->status, ['resolved', 'dismissed'], true)) {
                throw ValidationException::withMessages([
                    'issue' => 'Una segnalazione risolta o archiviata non genera ordini.',
                ]);
            }

            $workOrder = WorkOrder::create([
                'tenant_id' => $user->tenant_id,
                'code' => WorkOrder::nextCode($user->tenant_id),
                'title' => "Segnalazione {$issue->code}".($issue->reporter_name ? " ({$issue->reporter_name})" : ''),
                'description' => $issue->description,
                'status' => 'draft',
                'priority' => in_array($issue->severity, ['high', 'critical'], true) ? 'urgent' : 'normal',
                'origin' => 'issue',
                'origin_id' => $issue->id,
                'area_id' => $issue->area_id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            if ($issue->asset_id !== null) {
                \App\Models\WorkOrderAsset::create([
                    'tenant_id' => $user->tenant_id,
                    'work_order_id' => $workOrder->id,
                    'asset_id' => $issue->asset_id,
                ]);
            }

            // La presa in carico è implicita: si sta organizzando l'intervento
            $issue->work_order_id = $workOrder->id;
            if ($issue->status === 'open') {
                $issue->status = 'in_charge';
                $issue->taken_charge_at = now();
            }
            $issue->save();

            Audit::log('work_order.created', $workOrder, ['code' => $workOrder->code, 'origin' => 'issue']);

            return $issue;
        });

        return response()->json(['data' => $this->presented($issue)]);
    }

    private function presented(Issue $issue): array
    {
        return [
            ...$issue->load([
                'asset:id,census_code', 'area:id,name',
                'reporter:id,name', 'workOrder:id,code,status',
            ])->toArray(),
            'allowed_transitions' => Issue::TRANSITIONS[$issue->status] ?? [],
        ];
    }

}
