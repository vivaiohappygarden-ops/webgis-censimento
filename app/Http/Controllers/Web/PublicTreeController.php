<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Organization;
use App\Models\Photo;
use Illuminate\Support\Facades\Storage;

/**
 * Pagina pubblica raggiunta dal QR sul cartellino: nessun accesso, solo
 * dati divulgativi dell'elemento che l'organizzazione ha scelto di
 * pubblicare. Il gettone è casuale e revocabile in ogni momento.
 */
class PublicTreeController extends Controller
{
    private function assetByToken(string $token): Asset
    {
        return Asset::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('public_token', $token)
            ->with(['objectType', 'tree', 'area.locality.site'])
            ->firstOrFail();
    }

    public function show(string $token)
    {
        $asset = $this->assetByToken($token);

        $hasPhoto = Photo::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('asset_id', $asset->id)
            ->exists();

        return view('public.tree', [
            'asset' => $asset,
            'organization' => Organization::find($asset->tenant_id),
            'hasPhoto' => $hasPhoto,
            'token' => $token,
        ]);
    }

    /** La foto di riferimento (stessa regola dell'export: censimento, poi la più recente). */
    public function photo(string $token)
    {
        $asset = $this->assetByToken($token);

        $photo = Photo::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('asset_id', $asset->id)
            ->orderByRaw("(category = 'census') DESC")
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        abort_if($photo === null, 404);

        return Storage::disk()->response($photo->s3_key, $photo->original_filename, [
            'Content-Type' => $photo->mime_type ?: 'image/jpeg',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
