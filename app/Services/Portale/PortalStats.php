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

    /**
     * Si alza quando cambiano le voci calcolate qui dentro.
     *
     * Senza, per un quarto d'ora dopo un aggiornamento le pagine leggerebbero
     * un risultato vecchio a cui manca la chiave nuova, e mostrerebbero un
     * buco al posto di un dato che c'è: cambiando il nome della cassetta il
     * calcolo riparte da capo al primo che passa.
     */
    private const VERSIONE = 4;

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
            self::chiave($client),
            now()->addMinutes(self::MINUTI_DI_CACHE),
            $calcolo,
        );
    }

    public static function dimentica(Client $client): void
    {
        Cache::forget(self::chiave($client));
    }

    private static function chiave(Client $client): string
    {
        return 'portale:statistiche:v'.self::VERSIONE.':'.$client->id;
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
            'stati' => self::stati($client),
            ...self::stimeAmbientali($client),
            'ultimo_rilievo' => self::ultimoRilievo($client),
        ];
    }

    /**
     * Data dell'ultimo rilievo registrato fra gli elementi pubblicabili.
     *
     * Non è la data di oggi: dice da quando il registro non si muove, ed è
     * l'unica data che il portale può mostrare onestamente come "aggiornato
     * al". Nessun elemento con data di rilievo (censimento appena cominciato,
     * o importato senza date): non si scrive niente.
     */
    private static function ultimoRilievo(Client $client): ?string
    {
        $data = (clone PortalQuery::assets($client))->max('assets.surveyed_at');

        return $data === null ? null : substr((string) $data, 0, 10);
    }

    /**
     * Quanti elementi pubblicabili stanno in ciascuno dei quattro stati.
     *
     * Lo stato si calcola con la stessa identica espressione SQL che usano la
     * scheda e la mappa (PortalState::sql): la regola è scritta in un posto
     * solo, o la home direbbe una cosa e la scheda un'altra.
     *
     * Il raggruppamento si fa nel database, non in PHP: su un censimento
     * grande portarsi a casa una riga per elemento per poi contarle sarebbe
     * lavoro sprecato. Le quattro voci escono sempre tutte, anche a zero:
     * a decidere se un numero si mostra è la pagina, non il conteggio.
     *
     * @return array<string, int>
     */
    private static function stati(Client $client): array
    {
        $elementi = (clone PortalQuery::assets($client))
            ->select('assets.id')
            ->selectRaw(PortalState::sql().' as stato')
            ->toBase();

        $conteggi = DB::query()
            ->fromSub($elementi, 'stati')
            ->select('stato')
            ->selectRaw('count(*) as quanti')
            ->groupBy('stato')
            ->pluck('quanti', 'stato');

        $fuori = [];
        foreach (array_keys(PortalState::ETICHETTE) as $stato) {
            $fuori[$stato] = (int) ($conteggi[$stato] ?? 0);
        }

        return $fuori;
    }

    /**
     * Le stime ambientali del patrimonio arboreo: anidride carbonica
     * immagazzinata e gli altri benefici (ossigeno, polveri, pioggia).
     *
     * Le somme le fanno i due servizi, non questa pagina: la formula sta in
     * un posto solo, qui si passano gli alberi. Gli alberi arrivano a uno a
     * uno da un cursore, non in una lista: su un patrimonio da decine di
     * migliaia di piante tenerli tutti in memoria per sommarli sarebbe uno
     * spreco che si sente.
     *
     * Si calcola solo dove ci sono i dati che servono (diametro per la CO2,
     * età per l'ossigeno, chioma per polveri e pioggia): il conteggio degli
     * alberi effettivamente stimati viaggia insieme a ogni totale, perché un
     * numero senza il suo denominatore non si può leggere.
     *
     * @return array{co2: array, benefici: array|null}
     */
    private static function stimeAmbientali(Client $client): array
    {
        $co2 = \App\Services\Benefits\CarbonEstimate::totale(self::alberiPubblici($client));
        $benefici = \App\Services\Benefits\ServiziEcosistemici::totale(self::alberiPubblici($client));

        $prezzo = \App\Services\Benefits\CarbonEstimate::prezzoTonnellata();

        return [
            'co2' => [
                'kg' => $co2['co2_kg'],
                'alberi' => $co2['alberi'],
                // Controvalore economico: prezzo e fonte viaggiano NEL payload
                // (che sta in cache 15 minuti), così la pagina dichiara sempre
                // il prezzo con cui il numero è stato davvero calcolato, anche
                // se nel frattempo la configurazione è cambiata
                'euro' => $prezzo !== null ? round($co2['co2_kg'] / 1000 * $prezzo, 2) : null,
                'prezzo' => $prezzo,
                'fonte' => $prezzo !== null ? (string) config('co2.prezzo_fonte') : null,
            ],
            'benefici' => $benefici,
        ];
    }

    /**
     * Gli alberi pubblicabili del committente, uno alla volta, con i soli
     * campi che servono alle stime.
     *
     * @return \Generator<int, \App\Models\Tree>
     */
    private static function alberiPubblici(Client $client): \Generator
    {
        $righe = (clone PortalQuery::trees($client))
            ->select('trees.asset_id', 'trees.genus', 'trees.species',
                'trees.dbh_cm', 'trees.trunk_circumference_cm', 'trees.age_years_est',
                'trees.crown_diameter_m')
            ->orderBy('trees.asset_id')
            ->cursor();

        foreach ($righe as $riga) {
            $albero = new \App\Models\Tree;
            $albero->forceFill([
                'genus' => $riga->genus,
                'species' => $riga->species,
                'dbh_cm' => $riga->dbh_cm,
                'trunk_circumference_cm' => $riga->trunk_circumference_cm,
                'age_years_est' => $riga->age_years_est,
                'crown_diameter_m' => $riga->crown_diameter_m,
            ]);

            yield $albero;
        }
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
