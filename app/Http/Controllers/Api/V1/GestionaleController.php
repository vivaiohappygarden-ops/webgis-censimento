<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendToGestionale;
use App\Models\Asset;
use App\Models\GestionaleDispatch;
use App\Models\Organization;
use App\Models\Photo;
use App\Support\Audit;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Collegamento al gestionale WordPress (YourGarden in Cloud): dalla scheda
 * di un elemento si spedisce un "intervento da fare / da preventivare";
 * il gestionale la riceve come segnalazione nel suo flusso operativo.
 */
class GestionaleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:users.manage', only: ['settings', 'updateSettings', 'test']),
            new Middleware('can:works.manage', only: ['dispatchAsset', 'retry']),
            new Middleware('can:works.view', only: ['index']),
        ];
    }

    public function settings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentedSettings($request)]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['nullable', 'string', 'max:300', 'url'],
            // Il gettone si scrive ma non si rilegge mai per intero
            'token' => ['nullable', 'string', 'max:200'],
        ]);

        if (! empty($data['endpoint'])) {
            $host = parse_url($data['endpoint'], PHP_URL_HOST);
            $isLocal = in_array($host, ['127.0.0.1', 'localhost'], true);
            if (! str_starts_with($data['endpoint'], 'https://') && ! $isLocal) {
                throw ValidationException::withMessages([
                    'endpoint' => 'L\'indirizzo del gestionale deve iniziare con https://.',
                ]);
            }
        }

        $organization = Organization::query()->findOrFail($request->user()->tenant_id);
        $settings = $organization->settings ?? [];
        $current = $settings['gestionale'] ?? [];

        $settings['gestionale'] = [
            'endpoint' => $data['endpoint'] ?? null,
            // Gettone vuoto = conserva quello già salvato; per rimuoverlo
            // basta svuotare l'indirizzo
            'token' => ($data['token'] ?? '') !== '' ? trim($data['token']) : ($current['token'] ?? null),
        ];
        if (empty($settings['gestionale']['endpoint'])) {
            $settings['gestionale'] = ['endpoint' => null, 'token' => null];
        }
        $organization->forceFill(['settings' => $settings])->save();

        Audit::log('gestionale.settings_updated', null, [
            'configured' => ! empty($settings['gestionale']['endpoint']),
        ]);

        return response()->json(['data' => $this->presentedSettings($request)]);
    }

    /**
     * Prova del collegamento senza creare nulla nel gestionale: un corpo
     * volutamente non JSON. Risposta 400 = indirizzo e gettone corretti
     * (il gestionale ha letto la richiesta); 401 = gettone errato.
     */
    public function test(Request $request): JsonResponse
    {
        $config = $this->config($request);

        try {
            $response = Http::withHeaders([
                'X-YGC-Token' => $config['token'],
                'Content-Type' => 'application/json',
            ])->timeout(20)->withBody('prova-collegamento', 'application/json')
                ->post($config['endpoint']);
        } catch (\Throwable $e) {
            return response()->json(['data' => [
                'ok' => false,
                'message' => 'Il gestionale non risponde: controlla l\'indirizzo. ('.mb_strimwidth($e->getMessage(), 0, 120, '…').')',
            ]]);
        }

        $message = match (true) {
            $response->status() === 400 => 'Collegamento funzionante: indirizzo e gettone corretti.',
            $response->status() === 401 => 'Il gestionale risponde ma il gettone è assente o errato.',
            default => "Risposta inattesa dal gestionale (HTTP {$response->status()}): controlla l'indirizzo.",
        };

        return response()->json(['data' => [
            'ok' => $response->status() === 400,
            'message' => $message,
        ]]);
    }

    /** Crea l'invio per un elemento e lo mette in coda. */
    public function dispatchAsset(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'request_type' => ['required', Rule::in(GestionaleDispatch::TYPES)],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['required', Rule::in(GestionaleDispatch::PRIORITIES)],
            'photo_ids' => ['nullable', 'array', 'max:5'],
            'photo_ids.*' => ['uuid'],
        ]);

        $this->config($request);
        $asset = Asset::query()->findOrFail($id);

        // Le foto devono appartenere all'elemento: non si spediscono al
        // gestionale immagini di altri
        $photoIds = collect($data['photo_ids'] ?? [])->map(fn ($p) => strtolower($p))->unique();
        if ($photoIds->isNotEmpty()) {
            $owned = Photo::query()
                ->where('asset_id', $asset->id)
                ->whereIn('id', $photoIds)->count();
            if ($owned !== $photoIds->count()) {
                throw ValidationException::withMessages([
                    'photo_ids' => 'Una foto indicata non appartiene a questo elemento.',
                ]);
            }
        }

        $user = $request->user();
        $dispatch = DB::transaction(fn () => GestionaleDispatch::create([
            'tenant_id' => $user->tenant_id,
            'asset_id' => $asset->id,
            'external_ref' => GestionaleDispatch::nextRef($user->tenant_id),
            'request_type' => $data['request_type'],
            'title' => trim($data['title']),
            'description' => isset($data['description']) ? trim($data['description']) : null,
            'priority' => $data['priority'],
            'photo_ids' => $photoIds->values()->all(),
            'created_by' => $user->id,
        ]));

        SendToGestionale::dispatch($dispatch->id);
        Audit::log('gestionale.dispatched', $dispatch, ['external_ref' => $dispatch->external_ref]);

        return response()->json(['data' => $dispatch->fresh()], 201);
    }

    /** Gli invii di un elemento (o gli ultimi del tenant). */
    public function index(Request $request): JsonResponse
    {
        ListQuery::validateUuidFilters($request, ['asset_id']);

        $query = GestionaleDispatch::query()->with('author:id,name');
        if ($request->filled('asset_id')) {
            $query->where('asset_id', $request->string('asset_id'));
        }

        return response()->json([
            'data' => $query->orderByDesc('created_at')->limit(50)->get(),
        ]);
    }

    /**
     * Rimette in coda un invio: oltre a quelli non riusciti si sbloccano
     * quelli fermi in coda da più di dieci minuti (servizio delle code
     * spento, riavvio a metà strada). L'idempotenza rende sicuro il
     * ritento: il gestionale riconosce il riferimento e non duplica.
     */
    public function retry(Request $request, string $id): JsonResponse
    {
        $dispatch = GestionaleDispatch::query()->findOrFail($id);
        $stuck = $dispatch->status === 'pending'
            && $dispatch->updated_at?->lt(now()->subMinutes(10));
        if ($dispatch->status !== 'failed' && ! $stuck) {
            throw ValidationException::withMessages([
                'dispatch' => $dispatch->status === 'sent'
                    ? 'Questo invio è già stato consegnato.'
                    : 'Invio in corso: attendi qualche minuto prima di ritentare.',
            ]);
        }

        $this->config($request);
        $dispatch->forceFill(['status' => 'pending', 'last_error' => null])->save();
        SendToGestionale::dispatch($dispatch->id);
        Audit::log('gestionale.retried', $dispatch, ['external_ref' => $dispatch->external_ref]);

        return response()->json(['data' => $dispatch->fresh()]);
    }

    /** @return array{endpoint: string, token: string} */
    private function config(Request $request): array
    {
        $organization = Organization::query()->find($request->user()->tenant_id);
        $config = $organization?->settings['gestionale'] ?? [];
        if (empty($config['endpoint']) || empty($config['token'])) {
            throw ValidationException::withMessages([
                'gestionale' => 'Collegamento al gestionale non configurato: indirizzo e gettone si impostano dalla pagina Utenti.',
            ]);
        }

        return $config;
    }

    private function presentedSettings(Request $request): array
    {
        $organization = Organization::query()->find($request->user()->tenant_id);
        $config = $organization?->settings['gestionale'] ?? [];
        $token = $config['token'] ?? null;

        return [
            'endpoint' => $config['endpoint'] ?? null,
            // Mai il gettone intero: solo quanto basta a riconoscerlo
            'token_hint' => $token ? mb_substr($token, 0, 4).'…'.mb_substr($token, -4) : null,
            'configured' => ! empty($config['endpoint']) && ! empty($token),
        ];
    }
}
