<?php

namespace App\Jobs;

use App\Models\GestionaleDispatch;
use App\Models\Organization;
use App\Models\Photo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Spedisce una scheda al gestionale WordPress. La specifica garantisce
 * l'idempotenza su external_ref: in caso di esito incerto ritrasmettere
 * è sempre sicuro, quindi si ritenta con attese crescenti. Gli errori
 * definitivi (gettone errato, richiesta malformata) non si ritentano.
 */
class SendToGestionale implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public int $timeout = 120;

    public function __construct(public string $dispatchId) {}

    /** Attese crescenti come suggerito dalla specifica (5s, 30s, 2min...). */
    public function backoff(): array
    {
        return [5, 30, 120, 600, 1800];
    }

    public function handle(): void
    {
        $dispatch = GestionaleDispatch::withoutGlobalScopes()->find($this->dispatchId);
        if ($dispatch === null || $dispatch->status === 'sent') {
            return;
        }

        $organization = Organization::query()->find($dispatch->tenant_id);
        $config = $organization?->settings['gestionale'] ?? null;
        if (empty($config['endpoint']) || empty($config['token'])) {
            $dispatch->forceFill([
                'status' => 'failed',
                'last_error' => 'Collegamento al gestionale non configurato.',
            ])->save();

            return;
        }

        $dispatch->increment('attempts');

        try {
            // Niente inseguimento dei redirect: su un 301/302 Guzzle
            // trasformerebbe il POST in GET perdendo il corpo
            $response = Http::withHeaders(['X-YGC-Token' => $config['token']])
                ->timeout(90)
                ->acceptJson()
                ->withoutRedirecting()
                ->post($config['endpoint'], $this->payload($dispatch));
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Rete giù o host irraggiungibile: la coda ritenta, ma la
            // scheda deve dire intanto cosa sta succedendo
            $dispatch->forceFill([
                'last_error' => 'Il gestionale non risponde (problema di rete): nuovo tentativo automatico.',
            ])->save();
            throw $e;
        }

        $body = $response->json() ?? [];

        // 200 o 201 con ok: consegnato (200 = external_ref già noto: bene così)
        if ($response->successful() && ($body['ok'] ?? false)) {
            $dispatch->forceFill([
                'status' => 'sent',
                'remote_id' => isset($body['id']) ? (string) $body['id'] : null,
                'remote_url' => $body['url'] ?? null,
                'is_duplicate' => (bool) ($body['duplicate'] ?? false),
                'last_error' => null,
            ])->save();

            return;
        }

        // 2xx senza la conferma del gestionale: quasi certamente
        // l'indirizzo sbagliato (es. la home del sito). Definitivo,
        // o la riga resterebbe "in coda" per sempre
        if ($response->successful()) {
            $dispatch->forceFill([
                'status' => 'failed',
                'last_error' => "L'indirizzo risponde ma non come il gestionale: controlla l'endpoint nelle impostazioni (pagina Utenti).",
            ])->save();

            return;
        }

        // Reindirizzamento: serve l'indirizzo finale, ritentare non aiuta
        if ($response->redirect()) {
            $dispatch->forceFill([
                'status' => 'failed',
                'last_error' => "L'indirizzo reindirizza altrove (HTTP {$response->status()}): inserisci nelle impostazioni l'indirizzo finale.",
            ])->save();

            return;
        }

        // Solo gli errori del server (e i 408/429) meritano un ritento;
        // ogni altro rifiuto e' definitivo e resta spiegato nella scheda
        $retryable = $response->serverError() || in_array($response->status(), [408, 429], true);
        if (! $retryable) {
            $dispatch->forceFill([
                'status' => 'failed',
                'last_error' => $response->status() === 401
                    ? 'Gettone assente o errato: controlla il collegamento nella pagina Utenti.'
                    : ($body['errore'] ?? "Richiesta rifiutata dal gestionale (HTTP {$response->status()})."),
            ])->save();

            return;
        }

        $dispatch->forceFill([
            'last_error' => $body['errore'] ?? "Risposta HTTP {$response->status()} dal gestionale.",
        ])->save();
        $response->throw();
    }

    /** Dopo l'ultimo tentativo la riga resta consultabile con l'errore. */
    public function failed(?\Throwable $exception): void
    {
        GestionaleDispatch::withoutGlobalScopes()
            ->whereKey($this->dispatchId)
            ->where('status', '!=', 'sent')
            ->update([
                'status' => 'failed',
                'last_error' => mb_strimwidth((string) ($exception?->getMessage() ?: 'Invio non riuscito.'), 0, 500, '…'),
            ]);
    }

    /** La scheda nel formato della specifica YourGarden v1. */
    private function payload(GestionaleDispatch $dispatch): array
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT a.census_code, a.public_token,
                   ST_Y(ST_Centroid(a.geom)) AS lat, ST_X(ST_Centroid(a.geom)) AS lon,
                   t.species, t.common_name,
                   ar.name AS area_name, l.name AS locality_name, s.municipality
            FROM assets a
            LEFT JOIN trees t ON t.asset_id = a.id
            LEFT JOIN areas ar ON ar.id = a.area_id
            LEFT JOIN localities l ON l.id = ar.locality_id
            LEFT JOIN sites s ON s.id = l.site_id
            WHERE a.id = ?
            SQL, [$dispatch->asset_id]);

        $payload = [
            'external_ref' => $dispatch->external_ref,
            'tipo_richiesta' => $dispatch->request_type,
            'elemento' => array_filter([
                'codice' => $row?->census_code,
                'tipo' => $row?->species !== null || $row?->common_name !== null ? 'albero' : 'altro',
                'specie' => $row?->species,
                'nome_comune' => $row?->common_name,
                'comune' => $row?->municipality,
                'area' => $row?->area_name,
                'localita' => $row?->locality_name,
                'latitudine' => $row?->lat !== null ? round((float) $row->lat, 6) : null,
                'longitudine' => $row?->lon !== null ? round((float) $row->lon, 6) : null,
            ], fn ($v) => $v !== null),
            'problema' => array_filter([
                'titolo' => $dispatch->title,
                'descrizione' => $dispatch->description,
                'priorita' => $dispatch->priority,
            ], fn ($v) => $v !== null),
            'data_rilievo' => $dispatch->created_at->timezone('Europe/Rome')->toDateString(),
        ];

        if ($row?->public_token) {
            $payload['link_scheda'] = rtrim(config('app.url'), '/').'/p/'.$row->public_token;
        }

        $photos = $this->photos($dispatch);
        if ($photos !== []) {
            $payload['foto'] = $photos;
        }

        return $payload;
    }

    /**
     * Le foto scelte, in base64. Limiti della specifica: 5 per scheda,
     * 8 MB l'una, JSON complessivo sotto ~30 MB. Il tetto aggregato di
     * 20 MB grezzi (~27 in base64) tiene la richiesta dentro il limite
     * e la memoria del worker al sicuro; le foto oltre il tetto si
     * saltano, come fa il gestionale stesso con quelle fuori misura.
     */
    private function photos(GestionaleDispatch $dispatch): array
    {
        $ids = array_slice($dispatch->photo_ids ?? [], 0, 5);
        if ($ids === []) {
            return [];
        }

        $disk = Storage::disk();
        $budget = 20 * 1024 * 1024;
        $out = [];

        foreach (Photo::withoutGlobalScopes()
            ->where('tenant_id', $dispatch->tenant_id)
            ->whereIn('id', $ids)->get() as $photo) {
            if (! in_array($photo->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)
                || ! $disk->exists($photo->s3_key)) {
                continue;
            }
            $size = $disk->size($photo->s3_key);
            if ($size > 8 * 1024 * 1024 || $size > $budget) {
                continue;
            }
            $budget -= $size;
            $out[] = [
                'nome' => $photo->original_filename ?: 'foto.jpg',
                'mime' => $photo->mime_type,
                'base64' => base64_encode($disk->get($photo->s3_key)),
            ];
        }

        return $out;
    }
}
