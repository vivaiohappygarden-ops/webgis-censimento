<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Organization;
use App\Models\WorkOrder;
use App\Services\Pdf\PdfRenderer;
use App\Support\Audit;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Preventivi: lavorazioni dal catalogo o voci libere, intestate a un cliente; il flusso
 * bozza -> inviato -> accettato/rifiutato chiude il cerchio commerciale e
 * l'accettazione può diventare un ordine di lavoro con un gesto.
 */
class EstimateController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:works.view', only: ['index', 'show', 'pdf']),
            new Middleware('can:works.manage', only: ['store', 'update', 'destroy', 'syncItems', 'transition', 'createWorkOrder']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        ListQuery::validateUuidFilters($request, ['client_id']);
        $request->validate(['status' => ['nullable', Rule::in(Estimate::STATUSES)]]);

        $query = Estimate::query()
            ->with('client:id,name')
            ->withCount('items')
            ->withSum('items as subtotal', DB::raw('quantity * unit_price'));

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->string('client_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json([
            'data' => $query->orderByDesc('created_at')->limit(300)->get(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->presented($id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'uuid'],
            'area_id' => ['nullable', 'uuid'],
            'title' => ['required', 'string', 'max:200'],
            'vat_percent' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        Client::query()->findOrFail($data['client_id']);
        if (! empty($data['area_id'])) {
            Area::query()->findOrFail($data['area_id']);
        }

        $user = $request->user();
        $estimate = DB::transaction(fn () => Estimate::create([
            ...$data,
            'tenant_id' => $user->tenant_id,
            'code' => Estimate::nextCode($user->tenant_id),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]));

        Audit::log('estimate.created', $estimate, ['code' => $estimate->code]);

        return response()->json(['data' => $this->presented($estimate->id)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'version' => ['nullable', 'integer'],
            'client_id' => ['sometimes', 'uuid'],
            'area_id' => ['nullable', 'uuid'],
            'title' => ['sometimes', 'string', 'max:200'],
            'vat_percent' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        unset($data['version']);

        DB::transaction(function () use ($request, $id, $data) {
            $estimate = Estimate::query()->lockForUpdate()->findOrFail($id);
            $this->guardVersion($request, $estimate);
            $this->guardDraft($estimate);

            if (isset($data['client_id'])) {
                Client::query()->findOrFail($data['client_id']);
            }
            if (! empty($data['area_id'])) {
                Area::query()->findOrFail($data['area_id']);
            }

            $estimate->fill([...$data, 'updated_by' => $request->user()->id]);
            if ($estimate->isDirty()) {
                $estimate->version = $estimate->version + 1;
            }
            $estimate->save();
            Audit::log('estimate.updated', $estimate);
        });

        return response()->json(['data' => $this->presented($id)]);
    }

    public function destroy(string $id): Response
    {
        DB::transaction(function () use ($id) {
            $estimate = Estimate::query()->lockForUpdate()->findOrFail($id);
            if ($estimate->status !== 'draft') {
                throw ValidationException::withMessages([
                    'estimate' => 'Si elimina solo una bozza: un preventivo inviato resta agli atti (si può rifiutare).',
                ]);
            }
            // Le voci non hanno soft delete: con il preventivo eliminato
            // resterebbero orfane per sempre
            EstimateItem::query()->where('estimate_id', $estimate->id)->delete();
            $estimate->delete();
            Audit::log('estimate.deleted', $estimate, ['code' => $estimate->code]);
        });

        return response()->noContent();
    }

    /** Sostituzione integrale delle voci (solo in bozza). */
    public function syncItems(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'version' => ['nullable', 'integer'],
            'items' => ['present', 'array', 'max:100'],
            'items.*.work_type_id' => ['nullable', 'uuid'],
            'items.*.description' => ['required', 'string', 'max:300'],
            'items.*.unit' => ['required', 'string', 'max:20'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999'],
            'items.*.unit_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
        ]);

        DB::transaction(function () use ($request, $id, $data) {
            $estimate = Estimate::query()->lockForUpdate()->findOrFail($id);
            $this->guardVersion($request, $estimate);
            $this->guardDraft($estimate);

            $workTypeIds = collect($data['items'])->pluck('work_type_id')->filter()->unique();
            if ($workTypeIds->isNotEmpty()) {
                $found = \App\Models\WorkType::query()->whereIn('id', $workTypeIds)->count();
                if ($found !== $workTypeIds->count()) {
                    throw ValidationException::withMessages([
                        'items' => 'Una lavorazione indicata non esiste.',
                    ]);
                }
            }

            EstimateItem::query()->where('estimate_id', $estimate->id)->delete();
            foreach ($data['items'] as $index => $item) {
                EstimateItem::create([
                    'tenant_id' => $request->user()->tenant_id,
                    'estimate_id' => $estimate->id,
                    'sort_order' => $index + 1,
                    'work_type_id' => $item['work_type_id'] ?? null,
                    'description' => trim($item['description']),
                    'unit' => trim($item['unit']),
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                ]);
            }
            $estimate->version = $estimate->version + 1;
            $estimate->forceFill(['updated_by' => $request->user()->id])->save();
            Audit::log('estimate.items_updated', $estimate, ['count' => count($data['items'])]);
        });

        return response()->json(['data' => $this->presented($id)]);
    }

    /** Cambio di stato lungo il flusso ammesso. */
    public function transition(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'version' => ['nullable', 'integer'],
            'status' => ['required', Rule::in(Estimate::STATUSES)],
        ]);

        DB::transaction(function () use ($request, $id, $data) {
            $estimate = Estimate::query()->lockForUpdate()->findOrFail($id);
            $this->guardVersion($request, $estimate);
            $allowed = Estimate::TRANSITIONS[$estimate->status] ?? [];
            if (! in_array($data['status'], $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => "Passaggio non ammesso da \"{$estimate->status}\" a \"{$data['status']}\".",
                ]);
            }
            if ($data['status'] === 'sent' && ! EstimateItem::query()->where('estimate_id', $estimate->id)->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'Un preventivo senza voci non si può inviare.',
                ]);
            }
            $estimate->forceFill([
                'status' => $data['status'],
                'version' => $estimate->version + 1,
                'updated_by' => $request->user()->id,
            ])->save();
            Audit::log('estimate.transition', $estimate, ['to' => $data['status']]);
        });

        return response()->json(['data' => $this->presented($id)]);
    }

    /** L'accettazione diventa un ordine di lavoro in bozza (uno solo). */
    public function createWorkOrder(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        try {
            $workOrder = DB::transaction(function () use ($user, $id) {
                // Lock e controllo del duplicato DENTRO la transazione: due
                // richieste simultanee non possono passare entrambe
                $estimate = Estimate::query()->lockForUpdate()->findOrFail($id);
                if ($estimate->status !== 'accepted') {
                    throw ValidationException::withMessages([
                        'estimate' => 'Solo un preventivo accettato diventa un ordine di lavoro.',
                    ]);
                }
                $existing = WorkOrder::query()
                    ->where('origin', 'estimate')->where('origin_id', $estimate->id)->first();
                if ($existing) {
                    throw ValidationException::withMessages([
                        'estimate' => "Questo preventivo ha già generato l'ordine {$existing->code}.",
                    ]);
                }

                return WorkOrder::create([
                    'tenant_id' => $user->tenant_id,
                    'code' => WorkOrder::nextCode($user->tenant_id),
                    'title' => $estimate->title,
                    'status' => 'draft',
                    'origin' => 'estimate',
                    'origin_id' => $estimate->id,
                    'client_id' => $estimate->client_id,
                    'area_id' => $estimate->area_id,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Rete di sicurezza dell'indice univoco: stessa risposta del pre-check
            throw ValidationException::withMessages([
                'estimate' => 'Questo preventivo ha già generato un ordine di lavoro.',
            ]);
        }

        Audit::log('work_order.created', $workOrder, ['code' => $workOrder->code, 'origin' => 'estimate']);

        return response()->json(['data' => $workOrder], 201);
    }

    /** Il preventivo in PDF, pronto da mandare al cliente. */
    public function pdf(string $id, PdfRenderer $renderer)
    {
        $estimate = Estimate::query()
            ->with(['client:id,name', 'area:id,name', 'items.workType:id,name'])
            ->findOrFail($id);
        $organization = Organization::query()->find($estimate->tenant_id);

        [$subtotal, $vat] = $this->totals($estimate);

        $pdf = $renderer->render('pdf.estimate', [
            'estimate' => $estimate,
            'organization' => $organization,
            'subtotal' => $subtotal,
            'vat' => $vat,
            'total' => round($subtotal + $vat, 2),
        ]);

        Audit::log('estimate.pdf', $estimate, ['code' => $estimate->code]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="preventivo_'.$estimate->code.'.pdf"',
        ]);
    }

    private function guardDraft(Estimate $estimate): void
    {
        if ($estimate->status !== 'draft') {
            throw ValidationException::withMessages([
                'estimate' => 'Un preventivo non in bozza non si modifica: riportalo in bozza prima.',
            ]);
        }
    }

    private function guardVersion(Request $request, Estimate $estimate): void
    {
        if ($request->filled('version') && (int) $request->input('version') !== $estimate->version) {
            abort(409, "Conflitto di versione: il preventivo è stato modificato da altri (versione attuale {$estimate->version}).");
        }
    }

    private function presented(string $id): array
    {
        $estimate = Estimate::query()
            ->with(['client:id,name', 'area:id,name', 'items.workType:id,name'])
            ->findOrFail($id);

        [$subtotal, $vat] = $this->totals($estimate);

        return [
            ...$estimate->toArray(),
            'subtotal' => $subtotal,
            'vat' => $vat,
            'total' => round($subtotal + $vat, 2),
            'allowed_transitions' => Estimate::TRANSITIONS[$estimate->status] ?? [],
        ];
    }

    /**
     * Convenzione di fatturazione: ogni riga arrotondata a 2 decimali,
     * l'imponibile è la somma delle righe stampate e l'IVA si calcola su
     * quell'imponibile. Così la colonna del PDF quadra con la calcolatrice.
     *
     * @return array{0: float, 1: float}
     */
    private function totals(Estimate $estimate): array
    {
        $subtotal = round($estimate->items
            ->sum(fn ($i) => round((float) $i->quantity * (float) $i->unit_price, 2)), 2);
        $vat = round($subtotal * (float) $estimate->vat_percent / 100, 2);

        return [$subtotal, $vat];
    }
}
