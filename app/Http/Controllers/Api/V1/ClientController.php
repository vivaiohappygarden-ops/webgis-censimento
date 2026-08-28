<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Support\RicercaTestuale;
use App\Support\Audit;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:clients.view', only: ['index', 'show', 'sfondi']),
            new Middleware('can:clients.manage', only: ['store', 'update', 'destroy', 'stemma', 'rimuoviStemma']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = Client::query()->withCount('sites');

        if ($request->filled('q')) {
            $request->validate(['q' => RicercaTestuale::regole()]);
            // Anche partita IVA e codice fiscale: sono i dati con cui un
            // committente si cerca quando il nome non si ricorda per intero
            RicercaTestuale::applica($query, $request->string('q'), [
                'name', 'code', 'vat_number', 'fiscal_code',
            ]);
        }

        return response()->json(
            $query->orderBy('name')->paginate(ListQuery::perPage($request))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        // Prefisso delle etichette proposto dal nome (MEN per "Comune di
        // Mentana"): resta modificabile finché non ci sono codici assegnati
        $data['label_prefix'] ??= \App\Support\PortalLabels::uniquePrefix(
            $request->user()->tenant_id, $data['name'],
        );

        $client = Client::create($data);
        Audit::log('client.created', $client, ['name' => $client->name]);

        return response()->json(['data' => $client], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json([
            'data' => Client::query()
                ->with(['sites' => fn ($q) => $q->withCount('localities')->orderBy('name')])
                ->withCount('contracts')
                ->findOrFail($id),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        $data = $this->validated($request, $client);

        // Accendere il portale senza aver scelto l'indirizzo non deve essere
        // un errore: se manca, lo si ricava dal nome
        $acceso = array_key_exists('public_enabled', $data) ? $data['public_enabled'] : $client->public_enabled;
        $slug = array_key_exists('public_slug', $data) ? $data['public_slug'] : $client->public_slug;
        if ($acceso && ($slug === null || $slug === '')) {
            $data['public_slug'] = \App\Support\PortalLabels::uniqueSlug(
                $data['name'] ?? $client->name, $client->id,
            );
        }

        // Il profilo pubblico si aggiorna per chiavi: chi modifica il testo di
        // benvenuto non deve perdere colore e contatti
        if (array_key_exists('public_profile', $data)) {
            $data['public_profile'] = [...($client->public_profile ?? []), ...$data['public_profile']];
        }

        $client->update($data);
        Audit::log('client.updated', $client);

        return response()->json(['data' => $client->fresh()]);
    }

    /**
     * Stemma del Comune. Il file viene ricodificato subito in PNG: quello
     * che archiviamo è già ripulito dai metadati del file originale.
     */
    public function stemma(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'stemma' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
        ]);

        $client = Client::findOrFail($id);

        $png = \App\Services\Photos\ImageDerivative::png(
            file_get_contents($request->file('stemma')->getRealPath()), maxDimension: 512,
        );

        if ($png === null) {
            throw ValidationException::withMessages([
                'stemma' => 'Immagine non leggibile: usa un file JPEG, PNG o WEBP di dimensioni normali.',
            ]);
        }

        $path = "portale/{$client->tenant_id}/{$client->id}/stemma.png";
        \Illuminate\Support\Facades\Storage::disk()->put($path, $png);

        $client->public_profile = [...($client->public_profile ?? []), 'logo_path' => $path];
        $client->save();
        Audit::log('client.logo_updated', $client);

        return response()->json(['data' => $client->fresh()]);
    }

    public function rimuoviStemma(string $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        $profilo = $client->public_profile ?? [];

        if (! empty($profilo['logo_path'])) {
            \Illuminate\Support\Facades\Storage::disk()->delete($profilo['logo_path']);
        }
        unset($profilo['logo_path']);

        $client->public_profile = $profilo;
        $client->save();
        Audit::log('client.logo_removed', $client);

        return response()->json(['data' => $client->fresh()]);
    }

    public function destroy(string $id): Response
    {
        $client = Client::withCount('sites')->findOrFail($id);

        if ($client->sites_count > 0) {
            throw ValidationException::withMessages([
                'client' => "Il cliente ha {$client->sites_count} sedi collegate: eliminale prima.",
            ]);
        }

        $portalUsers = \App\Models\User::query()->where('client_id', $client->id)->count();
        if ($portalUsers > 0) {
            throw ValidationException::withMessages([
                'client' => "Il cliente ha {$portalUsers} utenti del portale collegati: disattivali o spostali prima.",
            ]);
        }

        $client->delete();
        Audit::log('client.deleted', $client);

        return response()->noContent();
    }

    /** Gli sfondi del committente pronti per la mappa del gestionale. */
    public function sfondi(string $id): JsonResponse
    {
        $client = Client::findOrFail($id);

        return response()->json([
            'data' => \App\Services\Carto\SfondiCommittente::perMappa($client),
        ]);
    }

    private function validated(Request $request, ?Client $existing = null): array
    {
        $required = $existing ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:254'],
            'code' => ['sometimes', 'nullable', 'string', 'max:40',
                Rule::unique('clients', 'code')->where(fn ($q) => $q
                    ->where('tenant_id', $request->user()->tenant_id)->whereNull('deleted_at'))
                    ->ignore($existing?->id),
            ],
            'client_type' => ['sometimes', 'in:public,private,condo,other'],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'fiscal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'pec' => ['sometimes', 'nullable', 'email'],
            'sdi_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'ipa_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'address' => ['sometimes', 'array'],
            'contacts' => ['sometimes', 'array'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],

            // Portale pubblico del committente
            'public_slug' => ['sometimes', 'nullable', 'string', 'max:40',
                'regex:/^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$/',
                // Fra i nomi riservati c'e' anche quello del gestionale, quando
                // sta sullo stesso dominio dei portali: assegnarlo a un
                // committente renderebbe irraggiungibile il programma
                Rule::notIn(\App\Support\PortalLabels::reservedSlugs()),
                // Unicità su TUTTO l'archivio: un sottodominio deve portare
                // a un solo committente, anche fra imprese diverse
                Rule::unique('clients', 'public_slug')
                    ->where(fn ($q) => $q->whereNull('deleted_at'))->ignore($existing?->id),
            ],
            'public_enabled' => ['sometimes', 'boolean'],

            // Sfondi cartografici del committente (z/x/y o WMS): le regole
            // stanno con la loro logica, in SfondiCommittente
            ...\App\Services\Carto\SfondiCommittente::regole(),

            'public_profile' => ['sometimes', 'array'],
            'public_profile.display_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'public_profile.welcome_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'public_profile.contact_email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'public_profile.color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'public_profile.footer_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'public_profile.show_co2' => ['sometimes', 'boolean'],
            'public_profile.legal_owner' => ['sometimes', 'nullable', 'string', 'max:500'],
            'public_profile.privacy_text' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'public_profile.accessibility_url' => ['sometimes', 'nullable', 'url', 'max:500'],

            // Prefisso delle etichette: la numerazione riparte da uno per
            // ogni committente (MEN-0001, GUI-0001)
            'label_prefix' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z0-9]{2,6}$/',
                Rule::unique('clients', 'label_prefix')->where(fn ($q) => $q
                    ->where('tenant_id', $request->user()->tenant_id)->whereNull('deleted_at'))
                    ->ignore($existing?->id),
            ],
        ], [
            'public_slug.regex' => 'L\'indirizzo del portale può contenere solo lettere minuscole, numeri e trattini, e non può iniziare o finire con un trattino.',
            'public_slug.not_in' => 'Questo indirizzo è riservato al servizio: scegline un altro.',
            'public_slug.unique' => 'Questo indirizzo è già usato da un altro committente.',
            'label_prefix.regex' => 'Il prefisso va da due a sei caratteri, solo lettere maiuscole e numeri (esempio: MEN).',
            'label_prefix.unique' => 'Questo prefisso è già assegnato a un altro committente.',
            'public_profile.color.regex' => 'Il colore va scritto in esadecimale, per esempio #14532d.',
        ]);
    }
}
