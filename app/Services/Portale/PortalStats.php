<?php

namespace App\Services\Portale;

use App\Models\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Numeri pubblici del patrimonio di un committente.
 *
 * Scelta del committente (18/08/2026): in pubblico non si mostra quanti
 * alberi sono stati abbattuti, perché letto da solo è un dato che sembra
 * negativo. Si mostra invece quanto patrimonio è stato curato e potato.
 * Gli abbattimenti restano nel gestionale, dove servono.
 *
 * Il conteggio costa diverse query: viene tenuto in memoria per un quarto
 * d'ora, tempo più che sufficiente per un dato che cambia con i rilievi.
 */
class PortalStats
{
    private const MINUTI_DI_CACHE = 15;

    /** Termini che identificano una potatura fra i tipi di lavorazione. */
    private const POTATURA = 'potatur|rimonda|capitozz|spalcatur|riformazione della chioma';

    private const ABBATTIMENTO = 'abbattiment|estirpaz|rimozione ceppa';

    public static function per(Client $client, bool $conCache = true): array
    {
        $calcolo = fn () => self::calcola($client);

        if (! $conCache) {
            return $calcolo();
        }

        return Cache::remember(
            "portale:statistiche:{$client->id}",
            now()->addMinutes(self::MINUTI_DI_CACHE),
            $calcolo,
        );
    }

    public static function dimentica(Client $client): void
    {
        Cache::forget("portale:statistiche:{$client->id}");
    }

    private static function calcola(Client $client): array
    {
        $elementi = (clone PortalQuery::assets($client))->count();

        $alberi = (clone PortalQuery::trees($client))->count();

        // Il campo species porta il binomio completo ("Tilia cordata"); il
        // genere fa da ripiego. Le schede senza specie non contano come varietà
        $varieta = (clone PortalQuery::trees($client))
            ->whereRaw("coalesce(nullif(trim(trees.species), ''), nullif(trim(trees.genus), '')) IS NOT NULL")
            ->distinct()
            ->count(DB::raw("lower(coalesce(nullif(trim(trees.species), ''), nullif(trim(trees.genus), '')))"));

        return [
            'elementi' => $elementi,
            'alberi' => $alberi,
            'varieta' => $varieta,
            'curati' => self::conInterventi($client, escludi: self::ABBATTIMENTO),
            'potati' => self::conInterventi($client, includi: self::POTATURA),
            'aggiornato_al' => now('Europe/Rome')->toDateString(),
        ];
    }

    /**
     * Alberi distinti con almeno un intervento concluso, filtrando per tipo
     * di lavorazione. Il tipo si legge dalla riga dell'ordine e, se manca,
     * dall'ordine stesso.
     */
    private static function conInterventi(Client $client, ?string $includi = null, ?string $escludi = null): int
    {
        $query = PortalQuery::trees($client)
            ->join('work_order_assets as woa', 'woa.asset_id', '=', 'assets.id')
            ->join('work_orders as wo', function ($j) {
                $j->on('wo.id', '=', 'woa.work_order_id')
                    ->where('wo.status', '=', 'completed')
                    ->whereNull('wo.deleted_at');
            })
            ->leftJoin('work_types as wt', fn ($j) => $j
                ->on('wt.id', '=', DB::raw('coalesce(woa.work_type_id, wo.work_type_id)')));

        if ($includi !== null) {
            $query->whereRaw("coalesce(wt.name, '') || ' ' || coalesce(wt.code, '') ~* ?", [$includi]);
        }
        if ($escludi !== null) {
            $query->whereRaw("coalesce(wt.name, '') || ' ' || coalesce(wt.code, '') !~* ?", [$escludi]);
        }

        return $query->distinct()->count('assets.id');
    }
}
