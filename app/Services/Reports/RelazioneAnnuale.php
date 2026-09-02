<?php

namespace App\Services\Reports;

use App\Models\Client;
use App\Models\Photo;
use App\Models\Tree;
use App\Services\Benefits\CarbonEstimate;
use App\Services\Photos\FotoStampa;
use App\Services\Photos\PublicPhotoCache;
use App\Services\Trees\TreeBalance;
use App\Support\AssetStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * I numeri della relazione annuale del verde di un committente.
 *
 * È il documento che a fine anno racconta all'ente il lavoro fatto:
 * patrimonio, bilancio arboreo, lavori, controlli, segnalazioni, trattamenti,
 * stima CO2 e una scelta di fotografie. Ogni sezione senza dati esce null e
 * la stampa la salta: la relazione di un committente appena preso non deve
 * essere una fila di zeri.
 *
 * I conteggi filtrano sempre per tenant E per committente in modo esplicito
 * (come TreeBalance): il servizio deve dare gli stessi numeri anche se un
 * giorno venisse chiamato fuori da una richiesta autenticata.
 *
 * Il patrimonio è una fotografia alla data di stampa, non una ricostruzione
 * al 31 dicembre: l'unica dinamica storicizzata per data è il bilancio
 * arboreo (planted_on/removed_on). La vista lo dichiara nella nota di metodo.
 */
class RelazioneAnnuale
{
    /** Fotografie stampate al massimo: oltre, il documento dichiara il totale. */
    public const MASSIMO_FOTO = 8;

    private const TIMEZONE = 'Europe/Rome';

    public static function per(string $tenantId, Client $client, int $anno): array
    {
        $inizio = "{$anno}-01-01";
        $fine = "{$anno}-12-31";

        // Confini dell'anno per le colonne timestamptz, in giorni italiani:
        // un lavoro chiuso alle 23 del 31 dicembre appartiene a quell'anno
        $daUtc = Carbon::create($anno, 1, 1, 0, 0, 0, self::TIMEZONE)->utc();
        $aUtc = Carbon::create($anno, 12, 31, 23, 59, 59, self::TIMEZONE)->utc();

        return [
            'anno' => $anno,
            'client' => $client->only(['id', 'name', 'code']),
            'patrimonio' => self::patrimonio($tenantId, $client->id),
            'bilancio' => self::bilancio($tenantId, $client->id, $inizio, $fine),
            'lavori' => self::lavori($tenantId, $client->id, $daUtc, $aUtc),
            'controlli' => self::controlli($tenantId, $client->id, $inizio, $fine, $daUtc, $aUtc),
            'segnalazioni' => self::segnalazioni($tenantId, $client->id, $daUtc, $aUtc),
            'fitosanitari' => self::fitosanitari($tenantId, $client->id, $inizio, $fine),
            'co2' => self::co2($tenantId, $client->id),
            'foto' => self::foto($tenantId, $client->id, $daUtc, $aUtc),
        ];
    }

    /**
     * Elementi censiti del committente (catena area -> località -> sede),
     * senza filtro di stato: chi chiama aggiunge il suo.
     */
    private static function elementi(string $tenantId, string $clientId): Builder
    {
        return DB::table('assets')
            ->join('areas', 'areas.id', '=', 'assets.area_id')
            ->join('localities', 'localities.id', '=', 'areas.locality_id')
            ->join('sites', 'sites.id', '=', 'localities.site_id')
            ->where('assets.tenant_id', $tenantId)
            ->where('sites.client_id', $clientId)
            ->whereNull('assets.deleted_at')
            ->whereNull('areas.deleted_at')
            ->whereNull('localities.deleted_at')
            ->whereNull('sites.deleted_at');
    }

    /** Come sopra, ma solo il patrimonio in gestione (fuori archivio). */
    private static function patrimonioInGestione(string $tenantId, string $clientId): Builder
    {
        return self::elementi($tenantId, $clientId)
            ->whereNotIn('assets.status', AssetStatus::ARCHIVIO);
    }

    /** Sottoquery degli id degli elementi del committente (per IN). */
    private static function idElementi(string $tenantId, string $clientId): Builder
    {
        return self::elementi($tenantId, $clientId)->select('assets.id');
    }

    /** Sottoquery degli id delle aree del committente (per IN). */
    private static function idAree(string $tenantId, string $clientId): Builder
    {
        return DB::table('areas')
            ->join('localities', 'localities.id', '=', 'areas.locality_id')
            ->join('sites', 'sites.id', '=', 'localities.site_id')
            ->where('areas.tenant_id', $tenantId)
            ->where('sites.client_id', $clientId)
            ->whereNull('areas.deleted_at')
            ->whereNull('localities.deleted_at')
            ->whereNull('sites.deleted_at')
            ->select('areas.id');
    }

    /**
     * Il patrimonio in gestione: sempre presente, è la ragione del documento.
     * Le schede in archivio (abbattute e dismesse) non contano: gli
     * abbattimenti dell'anno hanno la loro voce nel bilancio arboreo.
     */
    private static function patrimonio(string $tenantId, string $clientId): array
    {
        $elementi = self::patrimonioInGestione($tenantId, $clientId)->count();

        $alberiBase = fn () => self::patrimonioInGestione($tenantId, $clientId)
            ->join('trees', 'trees.asset_id', '=', 'assets.id')
            ->whereNull('trees.removed_on');

        // species porta il binomio completo; genus è il ripiego (come nel
        // portale pubblico): le schede senza specie non contano come varietà
        $varieta = $alberiBase()
            ->whereRaw("coalesce(nullif(trim(trees.species), ''), nullif(trim(trees.genus), '')) IS NOT NULL")
            ->distinct()
            ->count(DB::raw("lower(coalesce(nullif(trim(trees.species), ''), nullif(trim(trees.genus), '')))"));

        return [
            'elementi' => $elementi,
            'alberi' => $alberiBase()->count(),
            'varieta' => $varieta,
            'aree' => self::patrimonioInGestione($tenantId, $clientId)->distinct()->count('areas.id'),
            'localita' => self::patrimonioInGestione($tenantId, $clientId)->distinct()->count('localities.id'),
        ];
    }

    /**
     * Il bilancio arboreo dell'anno solare: stesso calcolo del documento di
     * legge 10/2013 (TreeBalance), mai un secondo conteggio che divergerebbe.
     */
    private static function bilancio(string $tenantId, string $clientId, string $inizio, string $fine): ?array
    {
        $bilancio = TreeBalance::per($tenantId, $inizio, $fine, $clientId);

        // Niente alberi né movimenti: la sezione non racconta nulla
        if ($bilancio['initial_count'] + $bilancio['planted_count']
            + $bilancio['felled_count'] + $bilancio['final_count'] === 0) {
            return null;
        }

        // Le specie più toccate dagli abbattimenti, per la lettura in stampa
        $bilancio['abbattute_per_specie'] = array_slice(
            array_values(array_filter($bilancio['by_species'], fn ($r) => (int) $r->felled > 0)),
            0, 10,
        );

        return $bilancio;
    }

    /** Gli ordini di lavoro chiusi nell'anno, per tipo di lavorazione. */
    private static function lavori(string $tenantId, string $clientId, Carbon $daUtc, Carbon $aUtc): ?array
    {
        $ordini = fn () => DB::table('work_orders as wo')
            ->where('wo.tenant_id', $tenantId)
            ->where('wo.client_id', $clientId)
            ->where('wo.status', 'completed')
            ->whereNull('wo.deleted_at')
            ->whereBetween('wo.completed_at', [$daUtc, $aUtc]);

        $totale = $ordini()->count();
        if ($totale === 0) {
            return null;
        }

        $perTipo = $ordini()
            ->leftJoin('work_types as wt', 'wt.id', '=', 'wo.work_type_id')
            ->selectRaw("coalesce(wt.name, 'Senza lavorazione') AS tipo, count(*) AS ordini")
            ->groupBy('tipo')
            ->orderByRaw('count(*) DESC, tipo')
            ->get();

        // Quantità consuntivate dai rapportini, per lavorazione e unità: sono
        // le stesse righe che alimentano rendiconto e SAL
        $quantita = $ordini()
            ->join('work_logs as wl', 'wl.work_order_id', '=', 'wo.id')
            ->leftJoin('work_types as wt', 'wt.id', '=', 'wo.work_type_id')
            ->whereNull('wl.deleted_at')
            ->whereNotNull('wl.quantity')
            ->whereNotNull('wl.unit')
            ->selectRaw("coalesce(wt.name, 'Senza lavorazione') AS tipo, wl.unit, sum(wl.quantity) AS quantita")
            ->groupBy('tipo', 'wl.unit')
            ->get()
            ->groupBy('tipo')
            ->map(fn ($righe) => $righe->pluck('quantita', 'unit')
                ->map(fn ($q) => round((float) $q, 2))->all());

        $ore = (float) $ordini()
            ->join('work_logs as wl', 'wl.work_order_id', '=', 'wo.id')
            ->whereNull('wl.deleted_at')
            ->sum('wl.man_hours');

        // SAL emessi: quelli validati nell'anno (la bozza non è un documento)
        $sal = DB::table('sals')
            ->where('tenant_id', $tenantId)
            ->where('client_id', $clientId)
            ->whereIn('status', ['validato', 'fatturato'])
            ->whereBetween('validated_at', [$daUtc, $aUtc])
            ->count();

        return [
            'ordini' => $totale,
            'ore' => round($ore, 1),
            'per_tipo' => $perTipo->map(fn ($r) => [
                'tipo' => $r->tipo,
                'ordini' => (int) $r->ordini,
                'quantita' => $quantita[$r->tipo] ?? [],
            ])->all(),
            'sal_emessi' => $sal,
        ];
    }

    /** Valutazioni VTA e ispezioni dell'anno, con gli esiti. */
    private static function controlli(string $tenantId, string $clientId, string $inizio, string $fine, Carbon $daUtc, Carbon $aUtc): ?array
    {
        $vta = fn () => DB::table('tree_assessments as ta')
            ->where('ta.tenant_id', $tenantId)
            ->whereNull('ta.deleted_at')
            ->whereBetween('ta.assessed_on', [$inizio, $fine])
            ->whereIn('ta.tree_id', self::idElementi($tenantId, $clientId));

        $vtaTotale = $vta()->count();
        $vtaPrescrizioni = $vta()
            // "Con prescrizioni": l'esito lo dice, oppure il campo è compilato
            ->where(fn ($q) => $q->where('ta.outcome', 'prescriptions')
                ->orWhereRaw("nullif(trim(ta.prescriptions), '') IS NOT NULL"))
            ->count();
        $vtaEsiti = $vta()
            ->whereNotNull('ta.outcome')
            ->selectRaw('ta.outcome, count(*) AS n')
            ->groupBy('ta.outcome')
            ->pluck('n', 'outcome');

        $ispezioni = fn () => DB::table('inspections as i')
            ->where('i.tenant_id', $tenantId)
            ->whereNull('i.deleted_at')
            ->whereNotNull('i.completed_at')
            ->whereBetween('i.completed_at', [$daUtc, $aUtc])
            ->where(fn ($q) => $q
                ->whereIn('i.asset_id', self::idElementi($tenantId, $clientId))
                ->orWhereIn('i.area_id', self::idAree($tenantId, $clientId)));

        $ispTotale = $ispezioni()->count();
        $ispEsiti = $ispezioni()
            ->whereNotNull('i.outcome')
            ->selectRaw('i.outcome, count(*) AS n')
            ->groupBy('i.outcome')
            ->pluck('n', 'outcome');

        if ($vtaTotale === 0 && $ispTotale === 0) {
            return null;
        }

        return [
            'vta' => $vtaTotale === 0 ? null : [
                'totale' => $vtaTotale,
                'con_prescrizioni' => $vtaPrescrizioni,
                'esiti' => $vtaEsiti->map(fn ($n) => (int) $n)->all(),
            ],
            'ispezioni' => $ispTotale === 0 ? null : [
                'totale' => $ispTotale,
                'esiti' => $ispEsiti->map(fn ($n) => (int) $n)->all(),
            ],
        ];
    }

    /**
     * Le segnalazioni arrivate nell'anno. Il committente si riconosce dal
     * campo diretto (segnalazioni dal portale cliente) o dall'elemento/area
     * segnalati: una segnalazione interna su un albero del Comune è comunque
     * lavoro fatto per quel Comune.
     */
    private static function segnalazioni(string $tenantId, string $clientId, Carbon $daUtc, Carbon $aUtc): ?array
    {
        $base = fn () => DB::table('issues as iss')
            ->where('iss.tenant_id', $tenantId)
            ->whereNull('iss.deleted_at')
            ->whereBetween('iss.created_at', [$daUtc, $aUtc])
            ->where(fn ($q) => $q
                ->where('iss.client_id', $clientId)
                ->orWhereIn('iss.asset_id', self::idElementi($tenantId, $clientId))
                ->orWhereIn('iss.area_id', self::idAree($tenantId, $clientId)));

        $ricevute = $base()->count();
        if ($ricevute === 0) {
            return null;
        }

        // Prima risposta = presa in carico. La media si mostra solo se il
        // dato c'è: su segnalazioni mai prese in carico non si inventa nulla
        $oreMedie = $base()
            ->whereNotNull('iss.taken_charge_at')
            ->selectRaw('avg(extract(epoch from (iss.taken_charge_at - iss.created_at)) / 3600.0) AS ore')
            ->value('ore');

        return [
            'ricevute' => $ricevute,
            'risolte' => $base()->whereNotNull('iss.resolved_at')->count(),
            'ore_prima_risposta' => $oreMedie === null ? null : round((float) $oreMedie, 1),
        ];
    }

    /** I trattamenti fitosanitari dell'anno (quaderno di campagna). */
    private static function fitosanitari(string $tenantId, string $clientId, string $inizio, string $fine): ?array
    {
        $base = fn () => DB::table('phyto_treatments as pt')
            ->where('pt.tenant_id', $tenantId)
            ->whereNull('pt.deleted_at')
            ->whereBetween('pt.treated_on', [$inizio, $fine])
            ->whereIn('pt.area_id', self::idAree($tenantId, $clientId));

        $totale = $base()->count();
        if ($totale === 0) {
            return null;
        }

        return [
            'totale' => $totale,
            'prodotti' => $base()
                ->selectRaw('pt.product_name, count(*) AS n')
                ->groupBy('pt.product_name')
                ->orderByRaw('count(*) DESC, pt.product_name')
                ->limit(5)
                ->get()
                ->map(fn ($r) => ['prodotto' => $r->product_name, 'trattamenti' => (int) $r->n])
                ->all(),
        ];
    }

    /**
     * La CO2 immagazzinata dal patrimonio: stessa strada del portale
     * (CarbonEstimate albero per albero, a blocchi), ma sul patrimonio in
     * gestione del gestionale, non sui soli elementi pubblicabili.
     */
    private static function co2(string $tenantId, string $clientId): ?array
    {
        $totale = 0.0;
        $contati = 0;
        $alberi = 0;

        self::patrimonioInGestione($tenantId, $clientId)
            ->join('trees', 'trees.asset_id', '=', 'assets.id')
            ->whereNull('trees.removed_on')
            ->select('trees.asset_id', 'trees.genus', 'trees.species',
                'trees.dbh_cm', 'trees.trunk_circumference_cm')
            ->orderBy('trees.asset_id')
            ->chunk(1000, function ($righe) use (&$totale, &$contati, &$alberi) {
                foreach ($righe as $riga) {
                    $alberi++;
                    $albero = new Tree;
                    $albero->forceFill([
                        'genus' => $riga->genus,
                        'species' => $riga->species,
                        'dbh_cm' => $riga->dbh_cm,
                        'trunk_circumference_cm' => $riga->trunk_circumference_cm,
                    ]);

                    $stima = CarbonEstimate::per($albero);
                    if ($stima !== null) {
                        $totale += $stima['co2_kg'];
                        $contati++;
                    }
                }
            });

        // Nessun albero con diametro: nessuna stima, meglio niente che finto
        if ($contati === 0) {
            return null;
        }

        $prezzo = CarbonEstimate::prezzoTonnellata();

        return [
            'kg' => round($totale, 1),
            'alberi_stimati' => $contati,
            'alberi_totali' => $alberi,
            'metodo' => (string) config('co2.modello'),
            'euro' => $prezzo !== null ? round($totale / 1000 * $prezzo, 2) : null,
            'prezzo' => $prezzo,
            'fonte' => $prezzo !== null ? (string) config('co2.prezzo_fonte') : null,
        ];
    }

    /**
     * Fino a MASSIMO_FOTO fotografie dell'anno, le più recenti, con la
     * didascalia (elemento, data, contesto). La data è quella dello scatto
     * e, dove manca, quella del caricamento: la stessa regola con cui le
     * ordina la scheda elemento (FotoStampa).
     *
     * @return array{foto: list<array{data: string, didascalia: string}>, totale: int}|null
     */
    private static function foto(string $tenantId, string $clientId, Carbon $daUtc, Carbon $aUtc): ?array
    {
        $base = fn () => Photo::query()
            ->withoutGlobalScopes()
            ->whereNull('photos.deleted_at')
            ->where('photos.tenant_id', $tenantId)
            ->whereIn('photos.asset_id', self::idElementi($tenantId, $clientId))
            ->whereRaw('coalesce(photos.taken_at, photos.created_at) BETWEEN ? AND ?', [$daUtc, $aUtc]);

        $totale = $base()->count();
        if ($totale === 0) {
            return null;
        }

        // Un margine di candidate oltre il tetto: un file illeggibile non
        // deve rubare il posto a una foto leggibile un po' più vecchia
        $candidate = $base()
            ->with(['asset' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'census_code')])
            ->orderByRaw('coalesce(photos.taken_at, photos.created_at) DESC, photos.id DESC')
            ->limit(self::MASSIMO_FOTO * 2)
            ->get();

        $foto = [];
        foreach ($candidate as $p) {
            if (count($foto) >= self::MASSIMO_FOTO) {
                break;
            }

            $jpeg = PublicPhotoCache::jpeg($p);
            if ($jpeg === null) {
                continue; // illeggibile: il totale in nota dice che c'era
            }

            $parti = array_filter([
                $p->asset?->census_code ? 'Elemento '.$p->asset->census_code : null,
                ($p->taken_at ?? $p->created_at)?->setTimezone(self::TIMEZONE)->format('d/m/Y'),
                FotoStampa::ETICHETTE[$p->category] ?? null,
            ]);

            $foto[] = [
                'data' => 'data:image/jpeg;base64,'.base64_encode($jpeg),
                'didascalia' => implode(' - ', $parti),
            ];
        }

        // Raccolte dalla più recente, si stampano in ordine di tempo
        $foto = array_reverse($foto);

        return $foto === [] ? null : ['foto' => $foto, 'totale' => $totale];
    }
}
