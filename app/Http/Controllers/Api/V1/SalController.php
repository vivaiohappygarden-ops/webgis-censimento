<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Sal;
use App\Models\SalItem;
use App\Models\WorkOrder;
use App\Services\Pdf\LuogoFirma;
use App\Services\Pdf\PdfRenderer;
use App\Services\Works\SalTotali;
use App\Services\Works\WorkOrderEconomics;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stati di avanzamento lavori.
 *
 * Il SAL nasce in bozza fotografando i lavori completati del committente nel
 * periodo, valorizzati dal listino; in bozza si correggono le aliquote IVA e
 * si tolgono righe. La validazione e' manuale: assegna il numero e congela
 * il documento. "Fatturato" e' solo un segno: i pagamenti non si gestiscono.
 */
class SalController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:works.manage')];
    }

    public function index(): JsonResponse
    {
        $sals = Sal::query()
            ->with(['client:id,name', 'items'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $sals->map(fn (Sal $sal) => $this->presenta($sal, conRighe: false))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'uuid'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['client_id' => 'committente', 'period_from' => 'inizio periodo', 'period_to' => 'fine periodo']);

        \App\Models\Client::query()->findOrFail($data['client_id']);

        $sal = DB::transaction(function () use ($request, $data) {
            // I completati del committente nel periodo, non ancora in un SAL.
            // I confini del periodo sono giorni ITALIANI come nel rendiconto:
            // i due elenchi sullo stesso periodo devono coincidere
            $ordini = WorkOrder::query()
                ->where('client_id', $data['client_id'])
                ->where('status', 'completed')
                ->whereNotNull('completed_at')
                ->whereBetween('completed_at', [
                    \Illuminate\Support\Carbon::parse($data['period_from'], 'Europe/Rome')->startOfDay()->utc(),
                    \Illuminate\Support\Carbon::parse($data['period_to'], 'Europe/Rome')->endOfDay()->utc(),
                ])
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('sal_items')->whereColumn('sal_items.work_order_id', 'work_orders.id'))
                ->with(['logs', 'workType:id,name,unit'])
                ->orderBy('completed_at')
                ->lockForUpdate()
                ->get();

            if ($ordini->isEmpty()) {
                throw ValidationException::withMessages([
                    'period_from' => 'Nel periodo non ci sono lavori completati da rendicontare per questo committente (o sono già in un altro SAL).',
                ]);
            }

            $pct = (float) config('sal.spese_generali_percento', 0);
            $sal = Sal::create([
                'tenant_id' => $request->user()->tenant_id,
                'client_id' => $data['client_id'],
                'period_from' => $data['period_from'],
                'period_to' => $data['period_to'],
                'notes' => $data['notes'] ?? null,
                // Le spese generali si fotografano ORA: accendere o cambiare
                // la percentuale in seguito non tocca i SAL gia' preparati
                'overhead_pct' => $pct > 0 ? $pct : null,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            $economia = app(WorkOrderEconomics::class);
            $aliquota = (float) config('sal.aliquota_predefinita', 22);
            foreach ($ordini as $i => $ordine) {
                $consuntivo = $economia->consuntivo($ordine);
                $valued = $consuntivo['valued'];
                $nota = null;
                $riga = ['unit' => null, 'quantity' => null, 'unit_price' => null, 'imponibile' => 0];
                if ($valued === null) {
                    $nota = 'Senza listino o lavorazione: importo da definire.';
                } elseif (! empty($valued['ambiguous'])) {
                    $nota = $valued['reason'];
                } else {
                    $riga = [
                        'unit' => $valued['unit'],
                        'quantity' => $valued['quantity'],
                        'unit_price' => $valued['unit_price'],
                        'imponibile' => $valued['amount'] ?? 0,
                    ];
                    if ($valued['quantity'] === null) {
                        $nota = 'Nessuna quantità registrata nell\'unità del listino ('.$valued['unit'].').';
                    }
                }

                SalItem::create($riga + [
                    'tenant_id' => $sal->tenant_id,
                    'sal_id' => $sal->id,
                    'work_order_id' => $ordine->id,
                    'descrizione' => trim(($ordine->code ? $ordine->code.' — ' : '').$ordine->title
                        .($ordine->workType ? ' ('.$ordine->workType->name.')' : '')),
                    'vat_rate' => $aliquota,
                    'nota' => $nota,
                    'sort_order' => $i,
                ]);
            }

            return $sal;
        });

        Audit::log('sal.created', $sal, ['client_id' => $sal->client_id]);

        return response()->json(['data' => $this->presenta($sal->fresh(['client:id,name', 'items']))], 201);
    }

    public function show(string $id): JsonResponse
    {
        $sal = Sal::query()->with(['client:id,name', 'items', 'validator:id,name'])->findOrFail($id);

        return response()->json([
            'data' => $this->presenta($sal),
            'aliquote' => config('sal.aliquote'),
        ]);
    }

    /** In bozza si correggono note e aliquote per riga; poi piu' niente. */
    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array'],
            'items.*.id' => ['required', 'uuid'],
            'items.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $sal = DB::transaction(function () use ($id, $data, $request) {
            $sal = Sal::query()->lockForUpdate()->findOrFail($id);
            $this->soloBozza($sal);

            if (array_key_exists('notes', $data)) {
                $sal->notes = $data['notes'];
            }
            $sal->updated_by = $request->user()->id;
            $sal->save();

            foreach ($data['items'] ?? [] as $riga) {
                SalItem::query()->where('sal_id', $sal->id)
                    ->whereKey($riga['id'])
                    ->update(['vat_rate' => round((float) $riga['vat_rate'], 2)]);
            }

            return $sal;
        });

        return response()->json(['data' => $this->presenta($sal->fresh(['client:id,name', 'items']))]);
    }

    /** Togliere una riga in bozza libera l'ordine per un SAL futuro. */
    public function rimuoviRiga(string $id, string $itemId): JsonResponse
    {
        $sal = DB::transaction(function () use ($id, $itemId) {
            $sal = Sal::query()->lockForUpdate()->findOrFail($id);
            $this->soloBozza($sal);
            SalItem::query()->where('sal_id', $sal->id)->whereKey($itemId)->firstOrFail()->delete();

            return $sal;
        });

        return response()->json(['data' => $this->presenta($sal->fresh(['client:id,name', 'items']))]);
    }

    public function destroy(string $id): JsonResponse
    {
        DB::transaction(function () use ($id) {
            $sal = Sal::query()->lockForUpdate()->findOrFail($id);
            $this->soloBozza($sal);
            $sal->delete();
            Audit::log('sal.deleted', $sal);
        });

        return response()->json(null, 204);
    }

    /** La validazione assegna il numero e congela il documento. */
    public function valida(Request $request, string $id): JsonResponse
    {
        $sal = DB::transaction(function () use ($request, $id) {
            $sal = Sal::query()->lockForUpdate()->findOrFail($id);
            $this->soloBozza($sal);
            if ($sal->items()->count() === 0) {
                throw ValidationException::withMessages([
                    'items' => 'Un SAL senza righe non si valida.',
                ]);
            }

            $sal->forceFill([
                'code' => $this->prossimoNumero($sal->tenant_id),
                'status' => 'validato',
                'validated_at' => now(),
                'validated_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ])->save();

            return $sal;
        });

        Audit::log('sal.validated', $sal, ['code' => $sal->code]);

        return response()->json(['data' => $this->presenta($sal->fresh(['client:id,name', 'items', 'validator:id,name']))]);
    }

    /** Solo un segno: i pagamenti non si gestiscono. */
    public function fatturato(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'invoice_ref' => ['nullable', 'string', 'max:100'],
        ], [], ['invoice_ref' => 'riferimento fattura']);

        $sal = DB::transaction(function () use ($request, $id, $data) {
            $sal = Sal::query()->lockForUpdate()->findOrFail($id);
            if ($sal->status !== 'validato') {
                abort(409, $sal->status === 'bozza'
                    ? 'Prima si valida il SAL, poi lo si segna come fatturato.'
                    : 'Questo SAL è già segnato come fatturato.');
            }
            $sal->forceFill([
                'status' => 'fatturato',
                'invoiced_at' => now(),
                'invoiced_by' => $request->user()->id,
                'invoice_ref' => $data['invoice_ref'] ?? null,
                'updated_by' => $request->user()->id,
            ])->save();

            return $sal;
        });

        Audit::log('sal.invoiced', $sal, ['invoice_ref' => $sal->invoice_ref]);

        return response()->json(['data' => $this->presenta($sal->fresh(['client:id,name', 'items']))]);
    }

    public function pdf(Request $request, PdfRenderer $renderer, string $id)
    {
        $sal = Sal::query()->with(['client:id,name', 'items', 'validator:id,name'])->findOrFail($id);

        // La data del foglio e' quella PROPRIA del documento: la validazione.
        // Solo una bozza, che una data sua non ce l'ha, usa la data di
        // stampa, letta una volta sola: mai due date diverse sullo stesso foglio
        $dataDocumento = $sal->validated_at?->timezone('Europe/Rome')
            ?? \Illuminate\Support\Carbon::now('Europe/Rome');

        $pdf = $renderer->render('pdf.sal', [
            'organization' => Organization::find($sal->tenant_id),
            'sal' => $sal,
            'totali' => SalTotali::per($sal),
            'dataDocumento' => $dataDocumento,
            'luogoData' => LuogoFirma::riga($sal->tenant_id, $dataDocumento),
        ]);

        $nome = ($sal->code ? strtolower($sal->code) : 'sal-bozza-'.substr($sal->id, 0, 8))
            .'-'.$dataDocumento->format('Ymd').'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nome.'"',
        ]);
    }

    private function soloBozza(Sal $sal): void
    {
        if ($sal->status !== 'bozza') {
            abort(409, 'Un SAL validato è immutabile: si corregge preparandone un altro.');
        }
    }

    /** SAL-anno-numero, col contatore che non torna mai indietro. */
    private function prossimoNumero(string $tenantId): string
    {
        DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 42))', ["sal:{$tenantId}"]);
        $anno = now('Europe/Rome')->year;
        $daRighe = (int) DB::selectOne(
            "SELECT COALESCE(MAX((substring(code FROM '\\d+$'))::int), 0) AS n
             FROM sals WHERE tenant_id = ? AND code LIKE ?",
            [$tenantId, "SAL-{$anno}-%"],
        )->n;

        $organization = Organization::query()->lockForUpdate()->findOrFail($tenantId);
        $settings = $organization->settings ?? [];
        $ultimo = (int) ($settings['sal_last_number'][(string) $anno] ?? 0);
        $next = max($daRighe, $ultimo) + 1;
        $settings['sal_last_number'][(string) $anno] = $next;
        $organization->forceFill(['settings' => $settings])->save();

        return sprintf('SAL-%d-%04d', $anno, $next);
    }

    private function presenta(Sal $sal, bool $conRighe = true): array
    {
        $totali = SalTotali::per($sal);

        $base = [
            'id' => $sal->id,
            'code' => $sal->code,
            'client' => $sal->client?->only(['id', 'name']),
            'period_from' => $sal->period_from?->toDateString(),
            'period_to' => $sal->period_to?->toDateString(),
            'status' => $sal->status,
            'notes' => $sal->notes,
            'overhead_pct' => $sal->overhead_pct !== null ? (float) $sal->overhead_pct : null,
            'validated_at' => $sal->validated_at?->toDateTimeString(),
            'validator' => $sal->relationLoaded('validator') ? $sal->validator?->only(['id', 'name']) : null,
            'invoiced_at' => $sal->invoiced_at?->toDateTimeString(),
            'invoice_ref' => $sal->invoice_ref,
            'totali' => $totali,
            'righe_totali' => $sal->items->count(),
        ];

        if ($conRighe) {
            $base['items'] = $sal->items->map(fn (SalItem $r) => [
                'id' => $r->id,
                'work_order_id' => $r->work_order_id,
                'descrizione' => $r->descrizione,
                'unit' => $r->unit,
                'quantity' => $r->quantity !== null ? (float) $r->quantity : null,
                'unit_price' => $r->unit_price !== null ? (float) $r->unit_price : null,
                'imponibile' => (float) $r->imponibile,
                'vat_rate' => (float) $r->vat_rate,
                'nota' => $r->nota,
            ])->values();
        }

        return $base;
    }
}
