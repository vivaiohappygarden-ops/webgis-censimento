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
 * il documento. Dopo la validazione si REGISTRANO (non si emettono: la
 * fattura elettronica la fa il commercialista) gli estremi della fattura e
 * poi l'incasso: "incassato" non e' uno stato ma paid_at valorizzata, e
 * "in ritardo" e' una condizione derivata dalla scadenza passata.
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
            // Oltre il tetto l'elenco e' tagliato: la pagina deve poterlo dire
            'totale' => Sal::query()->count(),
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

        try {
            $sal = $this->preparaBozza($request, $data);
        } catch (\Illuminate\Database\QueryException $e) {
            // Due "Prepara" simultanei sullo stesso periodo: il perdente cade
            // sull'indice unico (un ordine, un solo SAL) e deve uscire con un
            // messaggio comprensibile, non con un errore grezzo
            if (str_contains($e->getMessage(), 'uq_sal_items_ordine')) {
                throw ValidationException::withMessages([
                    'period_from' => 'Questi lavori sono appena entrati in un altro SAL: aggiorna l\'elenco e riprova.',
                ]);
            }
            throw $e;
        }

        Audit::log('sal.created', $sal, ['client_id' => $sal->client_id]);

        return response()->json(['data' => $this->presenta($sal->fresh(['client:id,name', 'items']))], 201);
    }

    private function preparaBozza(Request $request, array $data): Sal
    {
        return DB::transaction(function () use ($request, $data) {
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
                    } elseif (round((float) ($valued['base_amount'] ?? 0), 2) !== round((float) ($valued['amount'] ?? 0), 2)) {
                        // Quando la voce di listino maggiora l'importo, la
                        // moltiplicazione delle colonne non torna: il
                        // documento deve dichiarare il perche'
                        $pezzi = [];
                        if (! empty($valued['overhead_pct'])) {
                            $pezzi[] = 'spese generali '.rtrim(rtrim(number_format((float) $valued['overhead_pct'], 2, ',', '.'), '0'), ',').'%';
                        }
                        if (! empty($valued['safety_cost'])) {
                            $pezzi[] = 'oneri di sicurezza '.number_format((float) $valued['safety_cost'], 2, ',', '.').' euro';
                        }
                        $nota = 'L\'importo comprende le maggiorazioni della voce di listino'
                            .($pezzi !== [] ? ' ('.implode(', ', $pezzi).')' : '').'.';
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

    /**
     * Registra la fattura emessa dal commercialista: numero, data e
     * scadenza di pagamento. E' lo stesso passaggio di stato di prima
     * (validato -> fatturato), solo con gli estremi in piu': tutti
     * facoltativi lato server, cosi' un SAL fatturato "alla vecchia"
     * resta legittimo e la data, se manca, e' il giorno di oggi.
     */
    public function fatturato(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'invoice_ref' => ['nullable', 'string', 'max:100'],
            // Y-m-d obbligato: piu' sotto le date si confrontano come
            // stringhe, e solo in questa forma il confronto e' anche
            // cronologico
            'invoiced_at' => ['nullable', 'date_format:Y-m-d'],
            'payment_due_at' => ['nullable', 'date_format:Y-m-d'],
        ], [], [
            'invoice_ref' => 'numero fattura',
            'invoiced_at' => 'data fattura',
            'payment_due_at' => 'scadenza di pagamento',
        ]);

        // La data della fattura e' un giorno italiano di calendario
        $dataFattura = $data['invoiced_at'] ?? now('Europe/Rome')->toDateString();
        if (! empty($data['payment_due_at']) && $data['payment_due_at'] < $dataFattura) {
            throw ValidationException::withMessages([
                'payment_due_at' => 'La scadenza di pagamento non può precedere la data della fattura.',
            ]);
        }

        $sal = DB::transaction(function () use ($request, $id, $data, $dataFattura) {
            $sal = Sal::query()->lockForUpdate()->findOrFail($id);
            if ($sal->status !== 'validato') {
                abort(409, $sal->status === 'bozza'
                    ? 'Prima si valida il SAL, poi si registra la fattura.'
                    : 'Questo SAL è già segnato come fatturato.');
            }
            $sal->forceFill([
                'status' => 'fatturato',
                'invoiced_at' => $dataFattura,
                'invoiced_by' => $request->user()->id,
                'invoice_ref' => $data['invoice_ref'] ?? null,
                'payment_due_at' => $data['payment_due_at'] ?? null,
                'updated_by' => $request->user()->id,
            ])->save();

            return $sal;
        });

        Audit::log('sal.invoiced', $sal, [
            'invoice_ref' => $sal->invoice_ref,
            'invoiced_at' => $sal->invoiced_at?->toDateString(),
            'payment_due_at' => $sal->payment_due_at?->toDateString(),
        ]);

        return response()->json(['data' => $this->presenta($sal->fresh(['client:id,name', 'items']))]);
    }

    /**
     * Registra l'incasso: un fatto contabile, non un nuovo stato. Solo su
     * un SAL fatturato, con data non nel futuro e non prima della fattura.
     */
    public function registraIncasso(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'paid_at' => ['required', 'date_format:Y-m-d'],
            'paid_note' => ['nullable', 'string', 'max:200'],
        ], [], ['paid_at' => "data d'incasso", 'paid_note' => 'nota']);

        // Il "futuro" e' quello del calendario italiano: alle 23:30 di Roma
        // il server in UTC e' ancora a ieri e rifiuterebbe la data giusta
        if ($data['paid_at'] > now('Europe/Rome')->toDateString()) {
            throw ValidationException::withMessages([
                'paid_at' => "La data d'incasso non può essere nel futuro.",
            ]);
        }

        $sal = DB::transaction(function () use ($request, $id, $data) {
            $sal = Sal::query()->lockForUpdate()->findOrFail($id);
            if ($sal->status !== 'fatturato') {
                abort(409, "L'incasso si registra dopo la fattura: prima si registra la fattura sul SAL validato.");
            }
            if ($sal->paid_at !== null) {
                abort(409, "Questo SAL risulta già incassato: per correggere si annulla prima l'incasso registrato.");
            }
            if ($sal->invoiced_at !== null && $data['paid_at'] < $sal->invoiced_at->toDateString()) {
                throw ValidationException::withMessages([
                    'paid_at' => "La data d'incasso non può precedere la data della fattura ("
                        .$sal->invoiced_at->format('d/m/Y').').',
                ]);
            }
            $sal->forceFill([
                'paid_at' => $data['paid_at'],
                'paid_note' => $data['paid_note'] ?? null,
                'updated_by' => $request->user()->id,
            ])->save();

            return $sal;
        });

        Audit::log('sal.paid', $sal, ['paid_at' => $data['paid_at'], 'paid_note' => $sal->paid_note]);

        return response()->json(['data' => $this->presenta($sal->fresh(['client:id,name', 'items']))]);
    }

    /**
     * Annulla un incasso registrato per sbaglio: il SAL torna "fatturato
     * non incassato". L'audit tiene i valori tolti: un fatto contabile non
     * sparisce senza traccia.
     */
    public function annullaIncasso(Request $request, string $id): JsonResponse
    {
        [$sal, $tolto] = DB::transaction(function () use ($request, $id) {
            $sal = Sal::query()->lockForUpdate()->findOrFail($id);
            if ($sal->paid_at === null) {
                abort(409, 'Su questo SAL non risulta un incasso da annullare.');
            }
            $tolto = ['paid_at' => $sal->paid_at->toDateString(), 'paid_note' => $sal->paid_note];
            $sal->forceFill([
                'paid_at' => null,
                'paid_note' => null,
                'updated_by' => $request->user()->id,
            ])->save();

            return [$sal, $tolto];
        });

        Audit::log('sal.payment_cancelled', $sal, $tolto);

        return response()->json(['data' => $this->presenta($sal->fresh(['client:id,name', 'items']))]);
    }

    /**
     * Quadro dei crediti: per ogni committente (e in totale) quanto e'
     * emesso in attesa di fattura, quanto e' fatturato da incassare (e di
     * questo quanto e' oltre la scadenza, con i giorni medi di ritardo) e
     * quanto e' stato incassato nell'anno in corso.
     *
     * Gli importi passano da SalTotali, LA STESSA strada della pagina e
     * della stampa: una seconda formula in SQL prima o poi divergerebbe.
     */
    public function crediti(): JsonResponse
    {
        $oggi = now('Europe/Rome')->toDateString();
        $anno = now('Europe/Rome')->year;

        // Le bozze restano fuori: un documento non validato non e' un
        // credito. Degli incassati interessano solo quelli dell'anno
        $sals = Sal::query()
            ->with(['client:id,name', 'items'])
            ->where(fn ($q) => $q
                ->where('status', 'validato')
                ->orWhere(fn ($q2) => $q2->where('status', 'fatturato')->whereNull('paid_at'))
                ->orWhere(fn ($q3) => $q3->whereNotNull('paid_at')
                    ->whereBetween('paid_at', ["{$anno}-01-01", "{$anno}-12-31"])))
            ->get();

        $vuoto = [
            'emesso' => 0.0, 'da_incassare' => 0.0, 'scaduto' => 0.0,
            'incassato_anno' => 0.0, 'ritardo_somma' => 0, 'ritardo_conta' => 0,
        ];
        $totale = $vuoto;
        $righe = [];
        foreach ($sals as $sal) {
            $riga = $righe[$sal->client_id] ?? ['client' => $sal->client?->only(['id', 'name'])] + $vuoto;
            $importo = SalTotali::per($sal)['totale'];

            if ($sal->status === 'validato') {
                $riga['emesso'] = round($riga['emesso'] + $importo, 2);
                $totale['emesso'] = round($totale['emesso'] + $importo, 2);
            } elseif ($sal->paid_at === null) {
                $riga['da_incassare'] = round($riga['da_incassare'] + $importo, 2);
                $totale['da_incassare'] = round($totale['da_incassare'] + $importo, 2);
                $ritardo = $this->giorniRitardo($sal, $oggi);
                if ($ritardo !== null) {
                    $riga['scaduto'] = round($riga['scaduto'] + $importo, 2);
                    $totale['scaduto'] = round($totale['scaduto'] + $importo, 2);
                    $riga['ritardo_somma'] += $ritardo;
                    $riga['ritardo_conta']++;
                    $totale['ritardo_somma'] += $ritardo;
                    $totale['ritardo_conta']++;
                }
            } else {
                $riga['incassato_anno'] = round($riga['incassato_anno'] + $importo, 2);
                $totale['incassato_anno'] = round($totale['incassato_anno'] + $importo, 2);
            }

            $righe[$sal->client_id] = $riga;
        }

        // La media si chiude alla fine: durante il giro si sommano i giorni
        $chiudi = function (array $r): array {
            $r['ritardo_medio_giorni'] = $r['ritardo_conta'] > 0
                ? (int) round($r['ritardo_somma'] / $r['ritardo_conta'])
                : null;
            unset($r['ritardo_somma'], $r['ritardo_conta']);

            return $r;
        };

        $perCommittente = collect($righe)->map($chiudi)
            // Prima chi ha piu' scaduto, poi chi ha piu' da incassare: il
            // quadro serve a decidere chi sollecitare
            ->sortBy([['scaduto', 'desc'], ['da_incassare', 'desc'], ['client.name', 'asc']])
            ->values();

        return response()->json(['data' => [
            'anno' => $anno,
            'totale' => $chiudi($totale),
            'per_committente' => $perCommittente,
        ]]);
    }

    /**
     * Giorni di ritardo di un SAL fatturato non incassato oltre la
     * scadenza; null se non e' in ritardo (o senza scadenza registrata).
     */
    private function giorniRitardo(Sal $sal, string $oggi): ?int
    {
        if ($sal->status !== 'fatturato' || $sal->paid_at !== null || $sal->payment_due_at === null) {
            return null;
        }
        $scadenza = $sal->payment_due_at->toDateString();
        if ($scadenza >= $oggi) {
            return null;
        }

        // Date pure a mezzanotte nello stesso fuso: la differenza e' un
        // numero intero di giorni, senza resti di fuso orario
        return (int) \Illuminate\Support\Carbon::parse($scadenza)
            ->diffInDays(\Illuminate\Support\Carbon::parse($oggi));
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
            // Ore italiane, come la stampa: a cavallo della mezzanotte la
            // pagina e il PDF devono dire lo stesso giorno
            'validated_at' => $sal->validated_at?->timezone('Europe/Rome')->toDateTimeString(),
            'validator' => $sal->relationLoaded('validator') ? $sal->validator?->only(['id', 'name']) : null,
            // Le date contabili sono giorni di calendario, non istanti:
            // si presentano cosi' come sono scritte sulla fattura
            'invoiced_at' => $sal->invoiced_at?->toDateString(),
            'invoice_ref' => $sal->invoice_ref,
            'payment_due_at' => $sal->payment_due_at?->toDateString(),
            'paid_at' => $sal->paid_at?->toDateString(),
            'paid_note' => $sal->paid_note,
            // Il ritardo lo calcola il server, cosi' pagina e quadro dei
            // crediti dicono la stessa cosa (null = non in ritardo)
            'ritardo_giorni' => $this->giorniRitardo($sal, now('Europe/Rome')->toDateString()),
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
