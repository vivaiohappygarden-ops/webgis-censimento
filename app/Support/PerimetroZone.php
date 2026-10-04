<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use WeakMap;

/**
 * Il perimetro territoriale di un utente (punto 4 del committente, 04/10/2026).
 *
 * Un'organizzazione puo' dividersi in zone, ogni zona raccoglie dei
 * committenti. Un utente assegnato a una o piu' zone vede e tocca solo quello
 * che sta sotto quei committenti: sedi, localita', aree, elementi, lavori,
 * segnalazioni, trattamenti, valutazioni, foto. Chi non ha zone e' "centrale"
 * e non ha limiti. Che cosa puo' fare dentro il perimetro lo decide il ruolo,
 * come sempre.
 *
 * La regola sta qui una volta sola: lo scope dei modelli (ZonaScope) e le
 * interrogazioni scritte in SQL (tessere della mappa, scadenzari, documenti)
 * chiedono a questa classe. Il perimetro si legge una volta per richiesta
 * (memoria per istanza di utente): due letture nella stessa richiesta non
 * possono dire due cose diverse.
 */
final class PerimetroZone
{
    /** @var WeakMap<User, array{clienti: list<string>, zone: list<array{id: string, name: string}>}|null> */
    private static ?WeakMap $memoria = null;

    public static function azzera(): void
    {
        self::$memoria = new WeakMap;
    }

    /**
     * Gli id dei committenti visibili all'utente; null per l'utente centrale
     * (nessun limite). Un utente di zona senza committenti nelle sue zone
     * vede un elenco vuoto, non tutto.
     *
     * @return list<string>|null
     */
    public static function clienti(?User $user): ?array
    {
        return self::perimetro($user)['clienti'] ?? null;
    }

    public static function limitato(?User $user): bool
    {
        return self::perimetro($user) !== null;
    }

    /** @return list<array{id: string, name: string}> le zone dell'utente, per dirlo nel menu */
    public static function zone(?User $user): array
    {
        return self::perimetro($user)['zone'] ?? [];
    }

    /** Le zone le gestisce la sede centrale: un utente di zona non le tocca, qualunque ruolo abbia. */
    public static function autorizzaCentrale(?User $user): void
    {
        abort_if(self::limitato($user), 403, 'Le zone e le loro assegnazioni le gestisce la sede centrale.');
    }

    /**
     * Pezzo di WHERE per le interrogazioni scritte in SQL: la colonna indicata
     * deve essere un'area del perimetro. Vuoto per l'utente centrale.
     *
     * @return array{0: string, 1: list<string>}  [frammento che inizia con " AND ", valori]
     */
    public static function sqlAree(string $colonna, ?User $user): array
    {
        $clienti = self::clienti($user);
        if ($clienti === null) {
            return ['', []];
        }

        return [" AND {$colonna} IN (SELECT ar.id FROM areas ar JOIN localities l ON l.id = ar.locality_id JOIN sites s ON s.id = l.site_id WHERE s.client_id IN (".self::segnaposti($clienti).'))', $clienti];
    }

    /** @return array{0: string, 1: list<string>} */
    public static function sqlClienti(string $colonna, ?User $user): array
    {
        $clienti = self::clienti($user);
        if ($clienti === null) {
            return ['', []];
        }

        return [" AND {$colonna} IN (".self::segnaposti($clienti).')', $clienti];
    }

    /**
     * Gli ordini di lavoro del perimetro: per committente, per sede o per area.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function sqlLavori(string $alias, ?User $user): array
    {
        $clienti = self::clienti($user);
        if ($clienti === null) {
            return ['', []];
        }
        $s = self::segnaposti($clienti);

        return [" AND ({$alias}.client_id IN ({$s})"
            ." OR {$alias}.site_id IN (SELECT st.id FROM sites st WHERE st.client_id IN ({$s}))"
            ." OR {$alias}.area_id IN (SELECT ar.id FROM areas ar JOIN localities l ON l.id = ar.locality_id JOIN sites s ON s.id = l.site_id WHERE s.client_id IN ({$s})))",
            [...$clienti, ...$clienti, ...$clienti]];
    }

    /** @return array{clienti: list<string>, zone: list<array{id: string, name: string}>}|null */
    private static function perimetro(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }
        self::$memoria ??= new WeakMap;
        if (! isset(self::$memoria[$user])) {
            $zone = DB::table('zone_user')->join('zones', 'zones.id', '=', 'zone_user.zone_id')
                ->where('zone_user.user_id', $user->id)->where('zones.tenant_id', $user->tenant_id)
                ->orderBy('zones.name')->get(['zones.id', 'zones.name']);
            self::$memoria[$user] = $zone->isEmpty() ? null : [
                'zone' => $zone->map(fn ($z) => ['id' => $z->id, 'name' => $z->name])->values()->all(),
                'clienti' => DB::table('zone_client')->whereIn('zone_id', $zone->pluck('id')->all())->pluck('client_id')->unique()->values()->all(),
            ];
        }

        return self::$memoria[$user];
    }

    /** @param  list<string>  $valori */
    private static function segnaposti(array $valori): string
    {
        // Un elenco vuoto deve escludere tutto, non far esplodere la query
        return $valori ? implode(',', array_fill(0, count($valori), '?')) : 'NULL';
    }
}
