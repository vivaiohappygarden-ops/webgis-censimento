<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Organization;
use App\Models\Photo;
use App\Services\Photos\PublicPhotoCache;

/**
 * Pagina pubblica raggiunta dal QR sul cartellino: nessun accesso, solo
 * dati divulgativi dell'elemento che l'organizzazione ha scelto di
 * pubblicare. Il gettone è casuale e revocabile in ogni momento.
 */
class PublicTreeController extends Controller
{
    /** Solo le categorie divulgative: mai foto di difetti o segnalazioni. */
    private const PUBLIC_PHOTO_CATEGORIES = ['census', 'reference'];

    private function assetByToken(string $token): Asset
    {
        return Asset::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            // Un elemento abbattuto o dismesso non resta in vetrina
            ->where('status', 'active')
            // A contratto cessato le pagine si spengono da sole
            ->whereIn('tenant_id', Organization::query()->where('is_active', true)->select('id'))
            ->where('public_token', $token)
            // Anche le relazioni senza filtro tenant: un visitatore che è
            // GIÀ autenticato in un'altra organizzazione non deve vedere la
            // pagina rotta dai filtri della sua sessione
            ->with([
                'objectType' => fn ($q) => $q->withoutGlobalScopes(),
                'tree' => fn ($q) => $q->withoutGlobalScopes(),
                'area' => fn ($q) => $q->withoutGlobalScopes()->whereNull('deleted_at'),
                'area.locality' => fn ($q) => $q->withoutGlobalScopes(),
                'area.locality.site' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->firstOrFail();
    }

    private function publicPhoto(Asset $asset): ?Photo
    {
        return Photo::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('asset_id', $asset->id)
            ->whereIn('category', self::PUBLIC_PHOTO_CATEGORIES)
            ->orderByRaw("(category = 'census') DESC")
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    public function show(string $token)
    {
        $asset = $this->assetByToken($token);

        return view('public.tree', [
            'asset' => $asset,
            'organization' => Organization::find($asset->tenant_id),
            'hasPhoto' => $this->publicPhoto($asset) !== null,
            'token' => $token,
        ]);
    }

    /**
     * La foto viene SEMPRE ricodificata: il file originale può contenere
     * coordinate GPS e altri metadati che la pagina, per scelta, non
     * pubblica. Niente nome file originale.
     *
     * La copia ridotta si calcola una volta sola e resta sul disco del
     * server, non nel browser: la risposta è "no-store" e il gettone si
     * controlla prima, quindi revocarlo vale subito lo stesso. Senza questo
     * risparmio ogni visita ricodificherebbe l'originale a piena
     * risoluzione, ed è una pagina che chiunque può aprire.
     */
    public function photo(string $token)
    {
        $asset = $this->assetByToken($token);
        $photo = $this->publicPhoto($asset);
        abort_if($photo === null, 404);

        $jpeg = PublicPhotoCache::jpeg($photo);
        abort_if($jpeg === null, 404);

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => 'inline; filename="foto.jpg"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
