<?php

namespace App\Services\Portale;

use App\Models\Asset;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ricerca pubblica per numero di etichetta.
 *
 * Sul portale di un Comune il prefisso è sottinteso: il cittadino legge "42"
 * sul cartellino e lo scrive così com'è. Vengono provati, in ordine, il
 * codice scritto per intero, il codice completato con il prefisso del
 * committente e infine il codice del tag fisico.
 *
 * La ricerca non tocca mai le note interne: cercherebbe dentro testo che sul
 * portale non viene pubblicato.
 */
class PortalSearch
{
    public static function trova(Client $client, string $testo): ?Asset
    {
        $cercato = Str::upper(trim($testo));

        if ($cercato === '' || Str::length($cercato) > 80) {
            return null;
        }

        $candidati = [$cercato];

        if ($client->label_prefix !== null && preg_match('/^\d{1,6}$/', $cercato)) {
            $candidati[] = sprintf('%s-%04d', $client->label_prefix, (int) $cercato);
            $candidati[] = $client->label_prefix.'-'.$cercato;
        }

        $perCodice = PortalQuery::assets($client)
            ->whereIn(DB::raw('upper(assets.census_code)'), array_unique($candidati))
            ->first();

        if ($perCodice !== null) {
            return $perCodice;
        }

        // Codice del tag fisico: un QR o un codice a barre può portarlo
        return PortalQuery::assets($client)
            ->whereIn('assets.id', DB::table('asset_tags')
                ->where('tenant_id', $client->tenant_id)
                ->where('status', 'active')
                ->whereRaw('upper(uid) = ?', [$cercato])
                ->select('asset_id'))
            ->first();
    }

    /** Identificativo usato negli indirizzi pubblici: il codice, o l'id se manca. */
    public static function riferimento(Asset $asset): string
    {
        return $asset->census_code !== null && trim($asset->census_code) !== ''
            ? $asset->census_code
            : $asset->id;
    }

    /** Elemento indicato da un indirizzo pubblico. */
    public static function perRiferimento(Client $client, string $riferimento): ?Asset
    {
        $riferimento = trim($riferimento);

        if (Str::isUuid($riferimento)) {
            return PortalQuery::assets($client)->whereKey($riferimento)->first();
        }

        return PortalQuery::assets($client)
            ->whereRaw('upper(assets.census_code) = ?', [Str::upper($riferimento)])
            ->first();
    }
}
