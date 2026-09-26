<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Photo;
use App\Models\TreeAssessment;
use App\Models\WorkOrder;
use App\Services\Photos\ImageDerivative;
use App\Services\Portale\TerritorioCommittente;
use App\Support\Audit;
use App\Support\FiltriElementi;
use App\Support\IssueSla;
use App\Support\SitoDati;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Portale cliente in sola lettura: ogni utente agganciato a un cliente
 * (users.client_id) vede esclusivamente il proprio territorio — aree,
 * elementi, lavori completati e segnalazioni. Nessun dato economico.
 */
class PortalController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:portal.view')];
    }

    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->client_id === null) {
            return response()->json([
                'linked' => false,
                'message' => 'Questo utente non è ancora collegato a un cliente: chiedere all\'amministratore.',
            ]);
        }

        // Rapporto cessato (cliente eliminato o di un altro tenant): il
        // portale si chiude, non mostra dati orfani
        $territorio = TerritorioCommittente::perUtente($user);
        if ($territorio === null) {
            return response()->json([
                'linked' => false,
                'message' => 'Il collegamento al cliente non è più attivo: chiedere all\'amministratore.',
            ]);
        }

        $client = $territorio->client;
        $areaIds = $territorio->areaIds;
        // Le regole su ordini e segnalazioni stanno in TerritorioCommittente:
        // le stesse della mappa, dell'elenco e dei lavori del portale del Comune
        $orderScope = fn ($w) => $territorio->ordini($w);
        $issueScope = fn ($w) => $territorio->segnalazioni($w);

        $areas = Area::query()
            ->with('locality:id,name,site_id', 'locality.site:id,name')
            ->whereIn('id', $areaIds)
            ->withCount(['assets as elementi' => fn ($q) => $q->fuoriArchivio()])
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'code', 'locality_id', 'computed_area_sqm', 'status']);

        $orders = WorkOrder::query()
            ->with('area:id,name')
            ->where('status', 'completed')
            ->where($orderScope)
            ->orderByDesc('completed_at')
            ->limit(20)
            ->get(['id', 'code', 'title', 'status', 'completed_at', 'area_id']);

        $issues = Issue::query()
            ->with('area:id,name')
            ->where($issueScope)
            ->orderByRaw("CASE WHEN status IN ('resolved','dismissed') THEN 1 ELSE 0 END")
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'code', 'channel', 'status', 'severity', 'description', 'area_id', 'created_at', 'resolved_at']);

        // Dal 26/09/2026 la pagina del Comune legge anche i prossimi lavori,
        // le novita' degli ultimi trenta giorni, l'estensione del territorio
        // per la mappa e i recapiti dello studio
        $oggi = Carbon::now(PortaleComuneController::FUSO)->startOfDay();
        $da = $oggi->copy()->subDays(30);
        $inGestione = fn () => $territorio->elementi()->fuoriArchivio();
        $alberiInGestione = fn () => $inGestione()->whereHas('tree', fn ($t) => $t->whereNull('removed_on'));
        $perizie = fn () => TreeAssessment::query()
            ->whereNotNull('report_issued_at')
            ->whereIn('tree_id', $territorio->elementi()->select('assets.id'));
        $richieste = fn () => Issue::query()->where('channel', 'client_portal')->where('client_id', $client->id);

        $prossimi = WorkOrder::query()
            ->with(['area:id,name', 'workType:id,name'])
            ->whereIn('status', WorkOrder::FIELD_STATUSES)
            ->where($orderScope)
            ->orderByRaw('planned_start ASC NULLS LAST')->orderBy('created_at')
            ->limit(6)
            ->get();

        $estremi = DB::selectOne(
            'SELECT ST_XMin(e) AS x1, ST_YMin(e) AS y1, ST_XMax(e) AS x2, ST_YMax(e) AS y2, n FROM (
                SELECT ST_Extent(geom::geometry) AS e, COUNT(*) AS n FROM assets
                WHERE tenant_id = ? AND deleted_at IS NULL AND area_id IN ('.$territorio->segnapostoAree().')
            ) AS r',
            [$client->tenant_id, ...$territorio->legamiAree()],
        );
        if (($estremi?->x1 ?? null) === null) {
            // Senza elementi vale il perimetro delle aree, se disegnato
            $estremi = DB::selectOne(
                'SELECT ST_XMin(e) AS x1, ST_YMin(e) AS y1, ST_XMax(e) AS x2, ST_YMax(e) AS y2 FROM (
                    SELECT ST_Extent(geom::geometry) AS e FROM areas WHERE id IN ('.$territorio->segnapostoAree().') AND deleted_at IS NULL
                ) AS r',
                $territorio->legamiAree(),
            );
        }

        $ultimoRilievo = $territorio->elementi()->selectRaw('MAX(COALESCE(surveyed_at, created_at::date)) AS d')->value('d');
        $ultimaValutazione = TreeAssessment::query()
            ->whereIn('tree_id', $territorio->elementi()->select('assets.id'))->max('assessed_on');

        return response()->json([
            'linked' => true,
            'client' => ['name' => $client->name],
            'counts' => [
                'areas' => $areaIds->count(),
                // Solo il patrimonio in gestione: al cliente non si conta
                // l'archivio (abbattuti e dismessi)
                'assets' => $inGestione()->count(),
                'trees' => $alberiInGestione()->count(),
                'completed_orders' => WorkOrder::query()
                    ->where('status', 'completed')
                    ->where($orderScope)->count(),
                'open_orders' => WorkOrder::query()
                    ->whereIn('status', WorkOrder::FIELD_STATUSES)
                    ->where($orderScope)->count(),
                'open_issues' => Issue::query()
                    ->whereIn('status', ['open', 'in_charge'])
                    ->where($issueScope)->count(),
                'open_requests' => $richieste()->whereIn('status', ['open', 'in_charge'])->count(),
                'vta_scadute' => FiltriElementi::conVta($inGestione(), 'scaduta')->count(),
                'vta_mai' => FiltriElementi::conVta($inGestione(), 'mai')->count(),
                'documenti' => $perizie()->count(),
            ],
            'recenti' => [
                'giorni' => 30,
                'elementi_rilevati' => $territorio->elementi()->whereRaw('COALESCE(surveyed_at, created_at::date) >= ?', [$da->toDateString()])->count(),
                'valutazioni' => TreeAssessment::query()
                    ->whereIn('tree_id', $territorio->elementi()->select('assets.id'))
                    ->whereDate('assessed_on', '>=', $da->toDateString())->count(),
                'lavori_fatti' => WorkOrder::query()->where('status', 'completed')
                    ->where('completed_at', '>=', $da->toIso8601String())->where($orderScope)->count(),
                'richieste_risolte' => $richieste()->where('status', 'resolved')
                    ->where('resolved_at', '>=', $da->toIso8601String())->count(),
                'documenti' => $perizie()->where('report_issued_at', '>=', $da->toIso8601String())->count(),
            ],
            'ultimo_rilievo' => $ultimoRilievo ? Carbon::parse($ultimoRilievo)->toDateString() : null,
            'ultima_valutazione' => $ultimaValutazione ? Carbon::parse($ultimaValutazione)->toDateString() : null,
            'estensione' => ($estremi?->x1 ?? null) !== null
                ? ['sw' => [(float) $estremi->x1, (float) $estremi->y1], 'ne' => [(float) $estremi->x2, (float) $estremi->y2]]
                : null,
            'contatti' => $this->contatti($client->tenant_id),
            'prossimi' => $prossimi->map(fn (WorkOrder $o) => [
                'id' => $o->id,
                'code' => $o->code,
                'title' => $o->title,
                'status' => $o->status,
                'status_etichetta' => WorkOrder::STATUS_LABELS[$o->status] ?? $o->status,
                'tipo' => $o->workType?->name,
                'planned_start' => $o->planned_start?->toDateString(),
                'planned_end' => $o->planned_end?->toDateString(),
                'area' => $territorio->possiedeArea($o->area_id) ? $o->area?->name : null,
            ]),
            'areas' => $areas->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'code' => $a->code,
                'status' => $a->status,
                'locality' => $a->locality?->name,
                'site' => $a->locality?->site?->name,
                'area_sqm' => $a->computed_area_sqm !== null ? (float) $a->computed_area_sqm : null,
                'elementi' => (int) $a->elementi,
            ]),
            // Il nome dell'area compare solo se è davvero un'area del
            // cliente: mai nomi di aree altrui
            'orders' => $orders->map(fn ($o) => [
                'id' => $o->id,
                'code' => $o->code,
                'title' => $o->title,
                'completed_at' => $o->completed_at?->toIso8601String(),
                'area' => $territorio->possiedeArea($o->area_id) ? $o->area?->name : null,
            ]),
            'issues' => $issues->map(fn ($i) => [
                'code' => $i->code,
                'channel' => $i->channel,
                'status' => $i->status,
                'severity' => $i->severity,
                'description' => $i->description,
                'area' => $territorio->possiedeArea($i->area_id) ? $i->area?->name : null,
                'created_at' => $i->created_at?->toIso8601String(),
                'resolved_at' => $i->resolved_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Chi si occupa del verde del Comune: lo studio e il professionista come
     * sono scritti nelle impostazioni delle perizie, piu' i recapiti
     * dell'azienda di config/sito.php. Solo le voci compilate: il programma
     * non inventa recapiti.
     */
    private function contatti(string $tenantId): array
    {
        $organizzazione = Organization::query()->find($tenantId);
        $professionista = $organizzazione?->settings['professionista'] ?? [];
        $pulisci = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return array_filter([
            'studio' => $pulisci($organizzazione?->name),
            'professionista' => $pulisci($professionista['nome'] ?? null),
            'titolo' => $pulisci($professionista['titolo'] ?? null),
            'iscrizione' => $pulisci($professionista['iscrizione'] ?? null),
            'recapiti' => $pulisci($professionista['recapiti'] ?? null),
            'telefono' => SitoDati::testo('contatti.telefono'),
            'email' => SitoDati::testo('contatti.email'),
            'pec' => SitoDati::testo('contatti.pec'),
        ], fn ($v) => $v !== null);
    }

    /** Le aree del cliente collegato, o l'errore del portale se il collegamento manca. */
    private function linkedAreas(Request $request): array
    {
        $territorio = TerritorioCommittente::richiesto($request->user());

        return [$territorio->client, $territorio->areaIds];
    }

    /** Il cliente segnala un problema: diventa una segnalazione con i suoi tempi. */
    public function storeRequest(Request $request): JsonResponse
    {
        $user = $request->user();
        [$client, $areaIds] = $this->linkedAreas($request);

        $data = $request->validate([
            'description' => ['required', 'string', 'max:2000'],
            'area_id' => ['nullable', 'uuid'],
            // Il cliente indica l'urgenza percepita; "critica" resta una
            // valutazione dello staff
            'severity' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'photos' => ['nullable', 'array', 'max:3'],
            // 8 MB: oltre, la ricodifica di sicurezza rifiuterebbe comunque
            'photos.*' => ['file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
        ]);

        if (! empty($data['area_id']) && ! $areaIds->contains($data['area_id'])) {
            throw ValidationException::withMessages([
                'area_id' => 'L\'area indicata non appartiene al tuo territorio.',
            ]);
        }

        // Tetto giornaliero per cliente: il modulo è esposto agli account dei
        // clienti, un abuso non deve riempire disco ed elenco segnalazioni
        $todayCount = Issue::query()
            ->where('channel', 'client_portal')
            ->where('client_id', $user->client_id)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
        if ($todayCount >= 20) {
            throw ValidationException::withMessages([
                'description' => 'Limite giornaliero di richieste raggiunto: riprova domani o contattaci direttamente.',
            ]);
        }

        // Ricodifica PRIMA di salvare: elimina EXIF e coordinate GPS del
        // telefono del cliente (mai promesse allo staff) e normalizza il file
        $encodedPhotos = [];
        foreach ($request->file('photos') ?? [] as $index => $file) {
            $jpeg = ImageDerivative::jpeg(
                file_get_contents($file->getRealPath()), maxDimension: 2000, quality: 82,
            );
            if ($jpeg === null) {
                throw ValidationException::withMessages([
                    'photos' => 'Una delle foto non è leggibile o è troppo grande: riprova con un altro scatto.',
                ]);
            }
            $encodedPhotos[] = ['content' => $jpeg, 'original' => $file->getClientOriginalName()];
        }

        $severity = $data['severity'] ?? 'medium';
        $storedPaths = [];
        try {
            $issue = DB::transaction(function () use ($user, $client, $data, $severity, $encodedPhotos, &$storedPaths) {
                $issue = Issue::create([
                    'tenant_id' => $user->tenant_id,
                    'code' => Issue::nextCode($user->tenant_id),
                    'reporter_type' => 'client',
                    'reporter_user_id' => $user->id,
                    'reporter_name' => $client->name,
                    'channel' => 'client_portal',
                    'severity' => $severity,
                    'status' => 'open',
                    'area_id' => $data['area_id'] ?? null,
                    // La richiesta appartiene al cliente di OGGI, per sempre:
                    // se l'utente cambierà cliente, lo storico non lo segue
                    'client_id' => $user->client_id,
                    'description' => $data['description'],
                    'sla_due_at' => IssueSla::resolveDueAt(now(), $severity),
                    'taken_charge_due_at' => IssueSla::takeChargeDueAt(now(), $severity),
                ]);

                foreach ($encodedPhotos as $photo) {
                    $path = "photos/{$user->tenant_id}/issues/{$issue->id}/".Str::uuid7().'.jpg';
                    Storage::disk()->put($path, $photo['content']);
                    $storedPaths[] = $path;
                    Photo::create([
                        'tenant_id' => $user->tenant_id,
                        'subject_type' => 'issue',
                        'subject_id' => $issue->id,
                        'category' => 'issue',
                        's3_key' => $path,
                        'original_filename' => $photo['original'],
                        'mime_type' => 'image/jpeg',
                        'size_bytes' => strlen($photo['content']),
                        'hash_sha256' => hash('sha256', $photo['content']),
                        'taken_by' => $user->id,
                    ]);
                }

                return $issue;
            });
        } catch (\Throwable $e) {
            // Il filesystem non partecipa al rollback: niente file orfani
            foreach ($storedPaths as $path) {
                Storage::disk()->delete($path);
            }
            throw $e;
        }

        Audit::log('issue.created', $issue, ['code' => $issue->code, 'channel' => 'client_portal']);

        return response()->json(['data' => $this->presentRequest($issue->load('area:id,name')->loadCount('photos'))], 201);
    }

    /** Le richieste inviate dal portale dal proprio cliente (tutti i suoi utenti). */
    public function requests(Request $request): JsonResponse
    {
        $user = $request->user();
        [, $areaIds] = $this->linkedAreas($request);

        // Le richieste appartengono al CLIENTE registrato alla creazione:
        // un utente ricollegato a un altro cliente non travasa lo storico
        $issues = Issue::query()
            ->with(['area:id,name', 'workOrder:id,code,status,planned_start,completed_at'])
            ->withCount('photos')
            ->where('channel', 'client_portal')
            ->where('client_id', $user->client_id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $issues->map(fn (Issue $issue) => $this->presentRequest($issue, $areaIds)),
        ]);
    }

    private function presentRequest(Issue $issue, $areaIds = null): array
    {
        return [
            'id' => $issue->id,
            'code' => $issue->code,
            'severity' => $issue->severity,
            'status' => $issue->status,
            'description' => $issue->description,
            'area' => $areaIds === null || $areaIds->contains($issue->area_id) ? $issue->area?->name : null,
            'photos_count' => $issue->photos_count ?? 0,
            'created_at' => $issue->created_at?->toIso8601String(),
            'resolved_at' => $issue->resolved_at?->toIso8601String(),
            // Il cliente vede come è andata a finire, non le note interne
            'resolution_notes' => $issue->status === 'resolved' ? $issue->resolution_notes : null,
            // Il lavoro nato dalla richiesta, se c'e': il Comune ne segue lo
            // stato nella scheda Lavori senza chiedere all'ufficio
            'lavoro' => $issue->workOrder ? [
                'id' => $issue->workOrder->id,
                'code' => $issue->workOrder->code,
                'status' => $issue->workOrder->status,
                'status_etichetta' => WorkOrder::STATUS_LABELS[$issue->workOrder->status] ?? $issue->workOrder->status,
                'planned_start' => $issue->workOrder->planned_start?->toDateString(),
                'completed_at' => $issue->workOrder->completed_at?->setTimezone(PortaleComuneController::FUSO)->toDateString(),
            ] : null,
        ];
    }
}
