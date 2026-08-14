<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Issue;
use App\Models\WorkOrder;
use App\Models\WorkOrderAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

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
        $client = \App\Models\Client::query()->find($user->client_id);
        if ($client === null) {
            return response()->json([
                'linked' => false,
                'message' => 'Il collegamento al cliente non è più attivo: chiedere all\'amministratore.',
            ]);
        }

        $areaIds = Area::query()
            ->whereHas('locality.site', fn ($q) => $q->where('client_id', $user->client_id))
            ->pluck('id');

        // Un ordine appartiene al portale solo se coerente col cliente:
        // intestato a lui, oppure su una sua area, oppure senza area ma con
        // elementi suoi. Un ordine "misto" (area di un altro cliente) resta
        // fuori: mai rivelare lavori o aree altrui.
        $orderScope = function ($w) use ($areaIds, $user) {
            $w->where('client_id', $user->client_id)
                ->orWhereIn('area_id', $areaIds)
                ->orWhere(fn ($q) => $q->whereNull('area_id')
                    ->whereIn('id', WorkOrderAsset::query()
                        ->join('assets', 'assets.id', '=', 'work_order_assets.asset_id')
                        ->whereIn('assets.area_id', $areaIds)
                        ->select('work_order_assets.work_order_id')));
        };
        $issueScope = function ($w) use ($areaIds, $user) {
            $w->whereIn('area_id', $areaIds)
                ->orWhere(fn ($q) => $q->whereNull('area_id')
                    ->whereIn('asset_id', Asset::query()->whereIn('area_id', $areaIds)->select('id')))
                // Le richieste del cliente contano anche senza area indicata:
                // "Segnalazioni aperte: 0" sopra la propria richiesta aperta
                // sarebbe una contraddizione in piena vista
                ->orWhere(fn ($q) => $q->where('channel', 'client_portal')
                    ->where('client_id', $user->client_id));
        };

        $areas = Area::query()
            ->with('locality:id,name,site_id', 'locality.site:id,name')
            ->whereIn('id', $areaIds)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'code', 'locality_id', 'computed_area_sqm']);

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
            ->get(['id', 'code', 'status', 'severity', 'description', 'area_id', 'created_at', 'resolved_at']);

        return response()->json([
            'linked' => true,
            'client' => ['name' => $client?->name],
            'counts' => [
                'areas' => $areaIds->count(),
                'assets' => Asset::query()->whereIn('area_id', $areaIds)->count(),
                'completed_orders' => WorkOrder::query()
                    ->where('status', 'completed')
                    ->where($orderScope)->count(),
                'open_issues' => Issue::query()
                    ->whereIn('status', ['open', 'in_charge'])
                    ->where($issueScope)->count(),
            ],
            'areas' => $areas->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'code' => $a->code,
                'locality' => $a->locality?->name,
                'site' => $a->locality?->site?->name,
                'area_sqm' => $a->computed_area_sqm !== null ? (float) $a->computed_area_sqm : null,
            ]),
            // Il nome dell'area compare solo se è davvero un'area del
            // cliente: mai nomi di aree altrui
            'orders' => $orders->map(fn ($o) => [
                'code' => $o->code,
                'title' => $o->title,
                'completed_at' => $o->completed_at?->toIso8601String(),
                'area' => $areaIds->contains($o->area_id) ? $o->area?->name : null,
            ]),
            'issues' => $issues->map(fn ($i) => [
                'code' => $i->code,
                'status' => $i->status,
                'severity' => $i->severity,
                'description' => $i->description,
                'area' => $areaIds->contains($i->area_id) ? $i->area?->name : null,
                'created_at' => $i->created_at?->toIso8601String(),
                'resolved_at' => $i->resolved_at?->toIso8601String(),
            ]),
        ]);
    }

    /** Le aree del cliente collegato, o niente se il collegamento manca. */
    private function linkedAreas(Request $request): array
    {
        $user = $request->user();
        $client = $user->client_id ? \App\Models\Client::query()->find($user->client_id) : null;
        if ($client === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'client' => 'Questo utente non è collegato a un cliente: chiedere all\'amministratore.',
            ]);
        }

        $areaIds = Area::query()
            ->whereHas('locality.site', fn ($q) => $q->where('client_id', $user->client_id))
            ->pluck('id');

        return [$client, $areaIds];
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
            'severity' => ['nullable', \Illuminate\Validation\Rule::in(['low', 'medium', 'high'])],
            'photos' => ['nullable', 'array', 'max:3'],
            // 8 MB: oltre, la ricodifica di sicurezza rifiuterebbe comunque
            'photos.*' => ['file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
        ]);

        if (! empty($data['area_id']) && ! $areaIds->contains($data['area_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
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
            throw \Illuminate\Validation\ValidationException::withMessages([
                'description' => 'Limite giornaliero di richieste raggiunto: riprova domani o contattaci direttamente.',
            ]);
        }

        // Ricodifica PRIMA di salvare: elimina EXIF e coordinate GPS del
        // telefono del cliente (mai promesse allo staff) e normalizza il file
        $encodedPhotos = [];
        foreach ($request->file('photos') ?? [] as $index => $file) {
            $jpeg = \App\Services\Photos\ImageDerivative::jpeg(
                file_get_contents($file->getRealPath()), maxDimension: 2000, quality: 82,
            );
            if ($jpeg === null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'photos' => 'Una delle foto non è leggibile o è troppo grande: riprova con un altro scatto.',
                ]);
            }
            $encodedPhotos[] = ['content' => $jpeg, 'original' => $file->getClientOriginalName()];
        }

        $severity = $data['severity'] ?? 'medium';
        $storedPaths = [];
        try {
            $issue = \Illuminate\Support\Facades\DB::transaction(function () use ($user, $client, $data, $severity, $encodedPhotos, &$storedPaths) {
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
                    'sla_due_at' => \App\Support\IssueSla::resolveDueAt(now(), $severity),
                    'taken_charge_due_at' => \App\Support\IssueSla::takeChargeDueAt(now(), $severity),
                ]);

                foreach ($encodedPhotos as $photo) {
                    $path = "photos/{$user->tenant_id}/issues/{$issue->id}/".\Illuminate\Support\Str::uuid7().'.jpg';
                    \Illuminate\Support\Facades\Storage::disk()->put($path, $photo['content']);
                    $storedPaths[] = $path;
                    \App\Models\Photo::create([
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
                \Illuminate\Support\Facades\Storage::disk()->delete($path);
            }
            throw $e;
        }

        \App\Support\Audit::log('issue.created', $issue, ['code' => $issue->code, 'channel' => 'client_portal']);

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
            ->with('area:id,name')
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
        ];
    }
}
