<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Tree;
use App\Models\TreeAssessment;
use App\Services\Trees\PeriziaValidation;
use App\Support\Audit;
use App\Support\RicercaTestuale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Scadenzario VTA: ultima valutazione per albero, scadute e in scadenza. */
class VtaDashboardController extends Controller implements HasMiddleware
{
    /**
     * Tetto delle azioni collettive: oltre, la transazione tiene bloccate
     * troppe righe (stessa soglia delle azioni multiple del censimento).
     */
    private const MASSIMO_COLLETTIVA = 500;

    /** Esiti come compaiono a video, per il registro in CSV. */
    private const ESITI = [
        'ok' => 'Nessun intervento',
        'monitor' => 'Monitorare',
        'prescriptions' => 'Prescrizioni',
        'fell' => 'Abbattimento',
    ];

    /** Tipi di valutazione, versione breve da foglio di calcolo. */
    private const TIPI = [
        'vta_visual' => 'VTA visiva',
        'vta_instrumental' => 'VTA strumentale',
        'vsa' => 'VSA speditiva',
        'pull_test' => 'Prova di trazione',
        'aerial_inspection' => 'Ispezione in quota',
        'other' => 'Altra valutazione',
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('can:assets.view', except: ['valida']),
            // Validare e' un gesto che scrive atti: stesso permesso della
            // validazione singola
            new Middleware('can:assets.update', only: ['valida']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $clientId = $this->clientId($request);

        // Ultima valutazione per ogni albero (DISTINCT ON). Fuori l'archivio:
        // un albero abbattuto o dismesso non ha ricontrolli da programmare
        // ne' classi di rischio da esporre (removed_on da sola non basta,
        // un dismesso ce l'ha vuota)
        $fuoriArchivio = \App\Support\AssetStatus::sqlArchivio();
        $sql = <<<SQL
            SELECT latest.tree_id, latest.assessed_on, latest.failure_class,
                   latest.outcome, latest.next_check_due, a.census_code
            FROM (
              SELECT DISTINCT ON (ta.tree_id)
                     ta.tree_id, ta.assessed_on, ta.failure_class, ta.outcome, ta.next_check_due
              FROM tree_assessments ta
              WHERE ta.tenant_id = ? AND ta.deleted_at IS NULL
              ORDER BY ta.tree_id, ta.assessed_on DESC, ta.created_at DESC
            ) latest
            JOIN assets a ON a.id = latest.tree_id AND a.deleted_at IS NULL
                         AND a.status NOT IN ({$fuoriArchivio})
            JOIN trees t ON t.asset_id = latest.tree_id AND t.removed_on IS NULL
            SQL;
        $bindings = [$tenantId];

        if ($clientId !== null) {
            $sql .= "\n".<<<'SQL'
                JOIN areas ar ON ar.id = a.area_id
                JOIN localities lc ON lc.id = ar.locality_id
                JOIN sites s ON s.id = lc.site_id AND s.client_id = ?
                SQL;
            $bindings[] = $clientId;
        }

        $latest = collect(DB::select($sql, $bindings));

        $today = now()->toDateString();
        $soon = now()->addDays(30)->toDateString();

        $overdue = $latest->filter(fn ($r) => $r->next_check_due !== null && $r->next_check_due < $today)
            ->sortBy('next_check_due')->values();
        $upcoming = $latest->filter(fn ($r) => $r->next_check_due !== null && $r->next_check_due >= $today && $r->next_check_due <= $soon)
            ->sortBy('next_check_due')->values();

        $byClass = $latest->groupBy(fn ($r) => $r->failure_class ?? 'n.d.')
            ->map->count()
            ->sortKeys();

        // Il join esclude gli alberi il cui asset e' uscito dal censimento
        // (eliminato o in archivio)
        $treesTotal = Tree::query()
            ->join('assets', 'assets.id', '=', 'trees.asset_id')
            ->whereNull('assets.deleted_at')
            ->whereNotIn('assets.status', \App\Support\AssetStatus::ARCHIVIO)
            ->whereNull('trees.removed_on')
            ->when($clientId !== null, fn ($q) => $q
                ->join('areas', 'areas.id', '=', 'assets.area_id')
                ->join('localities', 'localities.id', '=', 'areas.locality_id')
                ->join('sites', 'sites.id', '=', 'localities.site_id')
                ->where('sites.client_id', $clientId))
            ->count();

        return response()->json([
            'data' => [
                'trees_total' => $treesTotal,
                'assessed' => $latest->count(),
                'never_assessed' => max(0, $treesTotal - $latest->count()),
                'overdue_count' => $overdue->count(),
                'upcoming_count' => $upcoming->count(),
                'by_class' => $byClass,
            ],
        ]);
    }

    /**
     * Elenco degli alberi con l'ultima valutazione: e' la lista di lavoro
     * dello scadenzario. I riquadri e le classi del cruscotto sono solo
     * conteggi: da qui si vede quali alberi sono dietro ai numeri.
     */
    public function alberi(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'nullable', 'in:assessed,never,overdue,upcoming,ok'],
            'class' => ['sometimes', 'nullable', 'in:A,B,C,C/D,D,nd'],
            'q' => ['sometimes', 'nullable', ...RicercaTestuale::regole()],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $tenantId = $request->user()->tenant_id;
        $today = now()->toDateString();
        $soon = now()->addDays(30)->toDateString();

        $ultima = DB::table('tree_assessments as ta')
            ->select(DB::raw('DISTINCT ON (ta.tree_id) ta.tree_id, ta.assessed_on, ta.failure_class, ta.outcome, ta.next_check_due, ta.validated_at'))
            ->where('ta.tenant_id', $tenantId)
            ->whereNull('ta.deleted_at')
            ->orderBy('ta.tree_id')->orderByDesc('ta.assessed_on')->orderByDesc('ta.created_at');

        // Quante valutazioni ha ogni albero e quante sono ancora in bozza:
        // servono per la validazione collettiva (si vede prima di agire)
        $conteggi = DB::table('tree_assessments as tc')
            ->selectRaw('tc.tree_id, COUNT(*) AS n_vta, COUNT(*) FILTER (WHERE tc.validated_at IS NULL) AS n_bozze')
            ->where('tc.tenant_id', $tenantId)
            ->whereNull('tc.deleted_at')
            ->groupBy('tc.tree_id');

        $query = Asset::query()
            ->join('trees', 'trees.asset_id', '=', 'assets.id')
            // Lo scadenzario e' la lista di lavoro: le schede in archivio non
            // hanno ricontrolli da programmare e non vi compaiono
            ->fuoriArchivio()
            ->whereNull('trees.removed_on')
            ->leftJoinSub($ultima, 'vta', 'vta.tree_id', '=', 'assets.id')
            ->leftJoinSub($conteggi, 'conte', 'conte.tree_id', '=', 'assets.id')
            ->leftJoin('areas', 'areas.id', '=', 'assets.area_id')
            ->leftJoin('localities', 'localities.id', '=', 'areas.locality_id')
            ->leftJoin('sites', 'sites.id', '=', 'localities.site_id')
            ->leftJoin('clients', 'clients.id', '=', 'sites.client_id')
            ->select([
                'assets.id', 'assets.census_code',
                'trees.genus', 'trees.species', 'trees.common_name',
                'areas.name as area_name', 'localities.name as locality_name',
                'clients.name as client_name',
                'vta.assessed_on', 'vta.failure_class', 'vta.outcome', 'vta.next_check_due',
                'vta.validated_at',
            ])
            ->selectRaw('COALESCE(conte.n_vta, 0) AS n_vta, COALESCE(conte.n_bozze, 0) AS n_bozze')
            // Lo stato si calcola con le stesse date del cruscotto (orologio
            // dell'applicazione, non del database): i numeri devono tornare
            ->selectRaw(
                "CASE WHEN vta.tree_id IS NULL THEN 'mai_valutato'"
                .' WHEN vta.next_check_due < ? THEN \'scaduto\''
                .' WHEN vta.next_check_due <= ? THEN \'in_scadenza\''
                ." ELSE 'valutato' END AS stato",
                [$today, $soon],
            );

        $query->when($request->input('client_id'), fn ($q, $clientId) => $q->where('sites.client_id', $clientId));

        match ($request->input('status')) {
            'assessed' => $query->whereNotNull('vta.tree_id'),
            'never' => $query->whereNull('vta.tree_id'),
            'overdue' => $query->where('vta.next_check_due', '<', $today),
            'upcoming' => $query->where('vta.next_check_due', '>=', $today)->where('vta.next_check_due', '<=', $soon),
            // Valutati e a posto: ricontrollo oltre i 30 giorni o senza data
            'ok' => $query->whereNotNull('vta.tree_id')
                ->where(fn ($w) => $w->whereNull('vta.next_check_due')->orWhere('vta.next_check_due', '>', $soon)),
            default => null,
        };

        match ($request->input('class')) {
            // "n.d.": valutato ma senza classe assegnata
            'nd' => $query->whereNotNull('vta.tree_id')->whereNull('vta.failure_class'),
            null, '' => null,
            default => $query->where('vta.failure_class', $request->input('class')),
        };

        $query->cercaTesto($request->input('q'));

        // Prima le urgenze: scaduti (dal piu' vecchio), in scadenza, mai
        // valutati, e in coda i valutati con ricontrollo lontano o senza data
        $query->orderByRaw(
            'CASE WHEN vta.tree_id IS NULL THEN 2'
            .' WHEN vta.next_check_due < ? THEN 0'
            .' WHEN vta.next_check_due <= ? THEN 1'
            .' ELSE 3 END',
            [$today, $soon],
        )
            ->orderByRaw('vta.next_check_due ASC NULLS LAST')
            ->orderBy('assets.census_code')
            ->orderBy('assets.id');

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    /**
     * Validazione collettiva: tutte le perizie in bozza degli alberi scelti
     * (o dell'intero committente) diventano atti in un colpo solo. Con
     * prova=1 si conta soltanto: il riepilogo che si legge prima di
     * confermare esce dallo stesso metodo dell'esecuzione.
     */
    public function valida(Request $request): JsonResponse
    {
        $request->validate([
            // Il flusso e' in due tempi: la prova risolve le bozze da alberi o
            // committente; la conferma rimanda gli id delle perizie contate
            // (assessment_ids), cosi' si valida esattamente cio' che si e'
            // letto in anteprima. Una bozza nata nel frattempo resta fuori;
            // una cambiata sotto viene riportata fra le saltate
            'assessment_ids' => ['required_without_all:asset_ids,client_id', 'array', 'min:1', 'max:'.self::MASSIMO_COLLETTIVA],
            'assessment_ids.*' => ['uuid'],
            'asset_ids' => ['required_without_all:assessment_ids,client_id', 'array', 'min:1', 'max:'.self::MASSIMO_COLLETTIVA],
            'asset_ids.*' => ['uuid'],
            'client_id' => ['required_without_all:assessment_ids,asset_ids', 'nullable', 'uuid'],
            'prova' => ['sometimes', 'boolean'],
        ]);

        if ($request->filled('assessment_ids')) {
            $bozze = collect($request->input('assessment_ids'));
        } else {
            // Si validano solo le bozze (le validate non sono un errore da
            // elencare) e solo di alberi in piedi: le schede in archivio
            // (abbattute o dismesse) non compaiono in nessuna parte della
            // pagina, e un numero d'anteprima piu' grande di tutto cio' che
            // si vede non sarebbe verificabile. La perizia di una scheda in
            // archivio si valida dalla sua scheda
            $bozze = TreeAssessment::query()
                ->whereNull('validated_at')
                ->whereHas('tree', fn ($t) => $t->whereNull('removed_on'))
                ->whereIn('tree_id', Asset::query()->select('assets.id')->fuoriArchivio())
                ->when($request->filled('asset_ids'),
                    fn ($q) => $q->whereIn('tree_id', $request->input('asset_ids')),
                    fn ($q) => $q->whereIn('tree_id', $this->alberiDelCommittente($request->input('client_id'))))
                ->orderBy('assessed_on')->orderBy('created_at')
                ->limit(self::MASSIMO_COLLETTIVA + 1)
                ->pluck('id');

            abort_if($bozze->count() > self::MASSIMO_COLLETTIVA, 422,
                'Piu\' di '.self::MASSIMO_COLLETTIVA.' perizie da validare in una volta: restringi la selezione.');
        }

        $esito = PeriziaValidation::validaTante($bozze->all(), $request->user(), $request->boolean('prova'));

        return response()->json(['data' => [
            'prova' => $request->boolean('prova'),
            'validate' => $esito['validate'],
            'saltate' => $esito['saltate'],
        ]]);
    }

    /**
     * Registro delle valutazioni in CSV: tutte le VTA (bozze e validate)
     * degli alberi scelti o del committente, una riga per valutazione.
     * E' il foglio da consegnare o da lavorare in Excel.
     */
    public function registro(Request $request): StreamedResponse
    {
        $request->validate([
            'asset_ids' => ['required_without:client_id', 'array', 'min:1', 'max:'.self::MASSIMO_COLLETTIVA],
            'asset_ids.*' => ['uuid'],
            'client_id' => ['required_without:asset_ids', 'nullable', 'uuid'],
        ]);

        $query = TreeAssessment::query()
            ->join('assets', fn ($j) => $j->on('assets.id', '=', 'tree_assessments.tree_id')->whereNull('assets.deleted_at'))
            ->leftJoin('trees', 'trees.asset_id', '=', 'assets.id')
            ->leftJoin('areas', 'areas.id', '=', 'assets.area_id')
            ->leftJoin('localities', 'localities.id', '=', 'areas.locality_id')
            ->leftJoin('sites', 'sites.id', '=', 'localities.site_id')
            ->leftJoin('clients', 'clients.id', '=', 'sites.client_id')
            ->when($request->filled('asset_ids'),
                fn ($q) => $q->whereIn('tree_assessments.tree_id', $request->input('asset_ids')),
                fn ($q) => $q->where('sites.client_id', $request->input('client_id')))
            ->select([
                'tree_assessments.*',
                'assets.census_code',
                'trees.genus', 'trees.species',
                'areas.name as area_name', 'localities.name as locality_name',
                'clients.name as client_name',
            ])
            ->orderBy('assets.census_code')
            ->orderBy('tree_assessments.assessed_on')
            ->orderBy('tree_assessments.created_at');

        Audit::log('export.vta_registro', null, ['filters' => $request->only(['client_id'])
            + ['alberi' => count($request->input('asset_ids', []))]]);

        // Testo libero neutralizzato: una cella che inizia con = + - @ ecc.
        // verrebbe eseguita da Excel come formula (iniezione CSV)
        $text = fn (?string $value) => $value !== null && preg_match('/^[=+\-@\t\r]/', $value)
            ? "'".$value
            : ($value ?? '');

        return response()->streamDownload(function () use ($query, $text) {
            $out = fopen('php://output', 'w');
            // BOM: senza, l'Excel italiano legge le lettere accentate sbagliate
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Codice albero', 'Specie', 'Committente', 'Località', 'Area',
                'Data valutazione', 'Tipo', 'Classe', 'Esito', 'Prescrizioni',
                'Prossima verifica', 'Protocollo', 'Stato', 'Validata il',
            ], ';', '"', '');

            foreach ($query->cursor() as $riga) {
                fputcsv($out, [
                    $text($riga->census_code),
                    // Il campo specie contiene già il binomio completo
                    $text($riga->species ?: ($riga->genus ?? '')),
                    $text($riga->client_name),
                    $text($riga->locality_name),
                    $text($riga->area_name),
                    $riga->assessed_on?->format('d/m/Y'),
                    self::TIPI[$riga->assessment_type] ?? $text($riga->assessment_type),
                    $riga->failure_class ?? '',
                    self::ESITI[$riga->outcome] ?? $text($riga->outcome),
                    $text($riga->prescriptions),
                    $riga->next_check_due?->format('d/m/Y'),
                    $text($riga->report_number),
                    $riga->validated_at !== null ? 'Validata' : 'Bozza',
                    $riga->validated_at?->timezone('Europe/Rome')->format('d/m/Y'),
                ], ';', '"', '');
            }
            fclose($out);
        }, 'registro_vta_'.now('Europe/Rome')->format('Ymd').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** Gli alberi di un committente, come sotto-interrogazione. */
    private function alberiDelCommittente(?string $clientId): \Illuminate\Database\Eloquent\Builder
    {
        return Asset::query()->select('assets.id')
            ->join('areas', 'areas.id', '=', 'assets.area_id')
            ->join('localities', 'localities.id', '=', 'areas.locality_id')
            ->join('sites', 'sites.id', '=', 'localities.site_id')
            ->where('sites.client_id', $clientId);
    }

    /** Alberi monumentali, soggetti a tutela o dedicati (L. 10/2013). */
    public function tutelati(Request $request): JsonResponse
    {
        $clientId = $this->clientId($request);

        $trees = Tree::query()
            ->join('assets', 'assets.id', '=', 'trees.asset_id')
            ->whereNull('assets.deleted_at')
            // Un tutelato abbattuto resta in elenco con la sua data (e' storia
            // che l'ente chiede); un dismesso invece e' un doppione o un
            // non-gestito e qui conterebbe una pianta due volte
            ->where('assets.status', '!=', 'dismissed')
            ->when($clientId !== null, fn ($q) => $q
                ->join('areas', 'areas.id', '=', 'assets.area_id')
                ->join('localities', 'localities.id', '=', 'areas.locality_id')
                ->join('sites', 'sites.id', '=', 'localities.site_id')
                ->where('sites.client_id', $clientId))
            ->where(fn ($w) => $w
                ->where('trees.is_monumental', true)
                ->orWhere('trees.is_protected', true)
                ->orWhere('trees.is_dedicated', true))
            ->select([
                'trees.asset_id', 'trees.genus', 'trees.species', 'trees.common_name',
                'trees.is_monumental', 'trees.monumental_ref',
                'trees.is_protected', 'trees.protection_ref',
                'trees.is_dedicated', 'trees.dedicated_to',
                'trees.removed_on',
                'assets.census_code',
            ])
            ->orderBy('assets.census_code')
            ->limit(500)
            ->get();

        return response()->json(['data' => $trees]);
    }

    /** Filtro per committente comune alle sezioni del cruscotto. */
    private function clientId(Request $request): ?string
    {
        $request->validate(['client_id' => ['sometimes', 'nullable', 'uuid']]);

        return $request->input('client_id') ?: null;
    }
}
