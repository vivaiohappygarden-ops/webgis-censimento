<?php

namespace App\Services\Portale;

use App\Models\Asset;
use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Regole di pubblicabilità del patrimonio sul portale del committente.
 *
 * Un solo punto per tutte le pagine pubbliche (ricerca, mappa, scheda,
 * statistiche): se una regola cambia, cambia in un posto solo. Senza utente
 * autenticato TenantScope non filtra nulla, quindi qui si filtra sempre a
 * mano, sia sull'impresa sia sul committente.
 *
 * Non finiscono mai in vetrina:
 *  - gli elementi nascosti a mano dal backoffice (public_hidden)
 *  - gli abbattuti o dismessi (status diverso da 'active')
 *  - gli elementi la cui validità è finita (valid_to passato)
 *  - le aree solo previste o dismesse
 */
class PortalQuery
{
    /** Elementi pubblicabili del committente. */
    public static function assets(Client $client): Builder
    {
        return Asset::query()
            ->withoutGlobalScopes()
            ->whereNull('assets.deleted_at')
            ->where('assets.tenant_id', $client->tenant_id)
            ->where('assets.public_hidden', false)
            ->where('assets.status', 'active')
            ->where(fn ($q) => $q->whereNull('assets.valid_to')
                ->orWhere('assets.valid_to', '>=', now('Europe/Rome')->toDateString()))
            ->whereIn('assets.area_id', self::areaIds($client));
    }

    /** Aree pubblicabili del committente (sottoquery, non collezione). */
    public static function areaIds(Client $client): \Illuminate\Database\Query\Builder
    {
        return DB::table('areas')
            ->join('localities', 'localities.id', '=', 'areas.locality_id')
            ->join('sites', 'sites.id', '=', 'localities.site_id')
            ->where('sites.client_id', $client->id)
            ->where('areas.tenant_id', $client->tenant_id)
            ->whereNull('areas.deleted_at')
            ->whereNull('localities.deleted_at')
            ->whereNull('sites.deleted_at')
            // Un'area solo prevista o dismessa non è patrimonio in gestione
            ->whereIn('areas.status', ['active', 'suspended'])
            ->select('areas.id');
    }

    /** Alberi pubblicabili: elementi con scheda albero e non rimossi. */
    public static function trees(Client $client): Builder
    {
        return self::assets($client)
            ->join('trees', 'trees.asset_id', '=', 'assets.id')
            ->whereNull('trees.removed_on');
    }
}
