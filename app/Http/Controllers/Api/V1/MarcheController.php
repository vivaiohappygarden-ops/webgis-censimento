<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarcaTemporale;
use App\Models\Organization;
use App\Services\Marche\MarcaTemporaleException;
use App\Services\Marche\MarcheTemporali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Marche temporali: l'elenco di quelle apposte, l'apposizione su un documento
 * chiuso, il PDF conservato e il gettone da scaricare, la verifica, e le
 * credenziali dell'organizzazione (chi gestisce gli utenti).
 */
class MarcheController extends Controller
{
    public function index(Request $request, MarcheTemporali $marche): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('assets.view') || $user->can('works.view'), 403);
        $request->validate(['tipo' => ['sometimes', 'nullable', Rule::in(array_keys(MarcheTemporali::TIPI))]]);

        $righe = MarcaTemporale::query()
            ->whereIn('tipo', $this->tipiVisibili($request))
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->string('tipo')->toString()))
            ->with('richiedente:id,name')
            ->orderByDesc('generato_il')
            ->limit(500)
            ->get()
            ->map(fn (MarcaTemporale $m) => self::riga($m));

        return response()->json([
            'data' => $righe->values()->all(),
            'stato' => $marche->stato($user->tenant_id),
            'tipi' => MarcheTemporali::TIPI,
        ]);
    }

    public function store(Request $request, MarcheTemporali $marche): JsonResponse
    {
        $data = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(MarcheTemporali::TIPI))],
            'id' => ['sometimes', 'nullable', 'uuid'],
            'parametri' => ['sometimes', 'array'],
            'parametri.anno' => ['sometimes', 'nullable', 'integer', 'between:2000,2100'],
            'parametri.client_id' => ['sometimes', 'nullable', 'uuid'],
            'parametri.area_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        abort_unless($request->user()->can(MarcheTemporali::PERMESSI[$data['tipo']]), 403);

        try {
            $marca = $marche->applica($request->user(), $data['tipo'], $data['id'] ?? null, $data['parametri'] ?? []);
        } catch (MarcaTemporaleException $e) {
            throw ValidationException::withMessages(['marca' => $e->getMessage()]);
        }

        return response()->json(['data' => self::riga($marca->load('richiedente:id,name'))], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => self::riga($this->trova($request, $id)->load('richiedente:id,name'))]);
    }

    /** Il PDF esatto che e' stato marcato: non una ristampa. */
    public function pdf(Request $request, string $id)
    {
        $marca = $this->trova($request, $id);
        $disco = Storage::disk();
        abort_unless($disco->exists($marca->percorso_pdf), 404, 'Il PDF conservato non si trova sul server.');
        $nome = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($marca->nome_file, PATHINFO_FILENAME)) ?: 'documento';

        return response($disco->get($marca->percorso_pdf), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$nome}_marcato.pdf\"",
        ]);
    }

    /** Il gettone RFC 3161 (.tsr), da conservare insieme al PDF e verificabile con qualunque programma. */
    public function marca(Request $request, string $id)
    {
        $marca = $this->trova($request, $id);
        $disco = Storage::disk();
        abort_unless($disco->exists($marca->percorso_marca), 404, 'Il gettone della marca non si trova sul server.');
        $nome = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($marca->nome_file, PATHINFO_FILENAME)) ?: 'documento';

        return response($disco->get($marca->percorso_marca), 200, [
            'Content-Type' => 'application/timestamp-reply',
            'Content-Disposition' => "attachment; filename=\"{$nome}.tsr\"",
        ]);
    }

    public function verifica(Request $request, string $id, MarcheTemporali $marche): JsonResponse
    {
        return response()->json(['data' => $marche->verifica($this->trova($request, $id))]);
    }

    // ---- Credenziali dell'organizzazione ---------------------------------------

    public function configurazione(Request $request, MarcheTemporali $marche): JsonResponse
    {
        $propria = Organization::query()->find($request->user()->tenant_id)?->settings['marche'] ?? [];

        return response()->json(['data' => [
            'url' => $propria['url'] ?? config('marche.url'),
            'utente' => $propria['utente'] ?? null,
            'ha_password' => ! empty($propria['password_cifrata']),
            'policy' => $propria['policy'] ?? null,
            'quota_giorno' => $propria['quota_giorno'] ?? null,
            'quota_predefinita' => (int) config('marche.quota_giorno'),
            // Il pacchetto lo assegna la piattaforma: qui si legge soltanto
            'pacchetto' => isset($propria['pacchetto']) && $propria['pacchetto'] !== '' ? (int) $propria['pacchetto'] : null,
            'stato' => $marche->stato($request->user()->tenant_id),
        ]]);
    }

    public function aggiornaConfigurazione(Request $request, MarcheTemporali $marche): JsonResponse
    {
        $data = $request->validate(self::regoleCredenziali(), [
            'policy.regex' => 'La politica di marcatura è un identificativo numerico a punti (OID), per esempio 1.3.76.36.1.1.1.',
        ]);

        // Il pacchetto non passa da qui: lo assegna la console della piattaforma
        $marche->salva(Organization::query()->findOrFail($request->user()->tenant_id), collect($data)->only(['url', 'utente', 'password', 'policy', 'quota_giorno'])->all());

        return $this->configurazione($request, $marche);
    }

    public function eliminaConfigurazione(Request $request, MarcheTemporali $marche): JsonResponse
    {
        $marche->togliCredenziali(Organization::query()->findOrFail($request->user()->tenant_id));

        return $this->configurazione($request, $marche);
    }

    /** Le regole dei campi delle credenziali, uguali per Documenti e per la console. */
    public static function regoleCredenziali(): array
    {
        return [
            'url' => ['required', 'url:http,https', 'max:300', function ($attributo, $valore, $fallisci) {
                $host = parse_url((string) $valore, PHP_URL_HOST);
                $locale = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
                if (! str_starts_with((string) $valore, 'https://') && ! $locale) {
                    $fallisci("L'indirizzo del servizio deve essere https: le credenziali viaggiano con la richiesta.");
                }
            }],
            'utente' => ['required', 'string', 'max:200'],
            'password' => ['nullable', 'string', 'max:200'],
            'policy' => ['nullable', 'string', 'max:100', 'regex:/^\d+(\.\d+)+$/'],
            'quota_giorno' => ['nullable', 'integer', 'between:0,10000'],
        ];
    }

    // ---- Comuni ------------------------------------------------------------------

    /** @return array<int,string> */
    private function tipiVisibili(Request $request): array
    {
        $user = $request->user();

        return array_keys(array_filter(MarcheTemporali::PERMESSI, fn ($permesso) => $user->can($permesso)));
    }

    private function trova(Request $request, string $id): MarcaTemporale
    {
        return MarcaTemporale::query()->whereIn('tipo', $this->tipiVisibili($request))->findOrFail($id);
    }

    public static function riga(MarcaTemporale $m): array
    {
        return [
            'id' => $m->id,
            'tipo' => $m->tipo,
            'tipo_etichetta' => MarcheTemporali::TIPI[$m->tipo] ?? $m->tipo,
            'soggetto_id' => $m->soggetto_id,
            'parametri' => $m->parametri,
            'titolo' => $m->titolo,
            'nome_file' => $m->nome_file,
            'sha256' => $m->sha256,
            'dimensione' => $m->dimensione,
            'generato_il' => $m->generato_il->toIso8601String(),
            'generato_il_locale' => $m->generato_il->timezone('Europe/Rome')->format('d/m/Y H:i:s'),
            'seriale' => $m->seriale,
            'tsa' => $m->tsa,
            'policy' => $m->policy,
            'servizio' => $m->servizio,
            'richiesta_da' => $m->relationLoaded('richiedente') ? $m->richiedente?->name : null,
            'created_at' => $m->created_at?->toIso8601String(),
            'pdf' => "/api/v1/documenti/marche/{$m->id}/pdf",
            'tsr' => "/api/v1/documenti/marche/{$m->id}/tsr",
            'verifica' => "/api/v1/documenti/marche/{$m->id}/verifica",
        ];
    }
}
