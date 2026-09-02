<?php

namespace App\Services\Works;

use App\Models\MaintenancePlan;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Genera gli ordini di lavoro dovuti dai piani di manutenzione in un periodo.
 *
 * Regola di casa (come AzioniMultiple): anteprima ed esecuzione passano DALLO
 * STESSO metodo, con $prova a decidere se scrivere. Mai due percorsi: se
 * divergono, il pre-conteggio mente.
 *
 * La ricorrenza parte dall'ultima volta REALE: l'ultimo ordine esistente
 * della stessa area e lavorazione (di qualunque origine, anche nato a mano)
 * fa da riferimento, e la scadenza successiva cade a interval_months da li'.
 * Senza precedenti si parte dalla prima occasione utile nel periodo. Una
 * scadenza che cade fuori dalla finestra stagionale slitta in avanti al
 * primo mese di finestra: il lavoro aspetta la stagione, non si perde.
 *
 * Idempotenza: ogni ordine generato porta plan_month (il mese di scadenza
 * coperto) accanto a origin/origin_id. Rilanciare la generazione non crea
 * doppioni: la scadenza gia' coperta si salta e lo si dice; l'indice unico
 * parziale sul DB e' la garanzia anche contro due lanci simultanei.
 */
class GeneratorePiani
{
    /** Oltre non si cammina: un riferimento vecchio decenni non deve far girare a vuoto. */
    private const MASSIMO_PASSI = 600;

    /**
     * @param  string  $daMese  primo mese del periodo, formato YYYY-MM
     * @param  string  $aMese  ultimo mese del periodo (incluso), formato YYYY-MM
     * @return array{creati: list<array>, saltati: list<array>}
     */
    public function genera(string $daMese, string $aMese, User $utente, bool $prova = false): array
    {
        $inizio = CarbonImmutable::createFromFormat('Y-m-d', $daMese.'-01')->startOfDay();
        $fine = CarbonImmutable::createFromFormat('Y-m-d', $aMese.'-01')->startOfDay();
        // Difesa in profondita': il controller valida gia', ma il tetto dei
        // 12 mesi e' un limite del servizio, non della sola API
        if ($fine->lessThan($inizio) || $inizio->diffInMonths($fine) >= 12) {
            throw new \InvalidArgumentException('Periodo non valido: da un mese a dodici, in ordine.');
        }

        $creati = [];
        $saltati = [];

        DB::transaction(function () use ($inizio, $fine, $utente, $prova, &$creati, &$saltati) {
            // Due generazioni simultanee dello stesso tenant si mettono in
            // fila: la seconda rilegge a lock preso e vede gli ordini della
            // prima, quindi salta invece di violare l'indice unico
            DB::statement(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 42))',
                ["piani:{$utente->tenant_id}"],
            );

            $piani = MaintenancePlan::query()
                ->where('is_active', true)
                ->with([
                    'area.locality.site.client:id,name',
                    'workType:id,name,unit',
                    'team.client:id,name',
                ])
                ->get();

            foreach ($piani as $piano) {
                $this->generaPerPiano($piano, $inizio, $fine, $utente, $prova, $creati, $saltati);
            }

            // Il registro racconta solo cose successe: niente audit in prova
            if (! $prova) {
                Audit::log('maintenance_plan.generated', null, [
                    'periodo' => $inizio->format('Y-m').'..'.$fine->format('Y-m'),
                    'creati' => count($creati),
                    'saltati' => count($saltati),
                ]);
            }
        });

        return ['creati' => $creati, 'saltati' => $saltati];
    }

    private function generaPerPiano(
        MaintenancePlan $piano,
        CarbonImmutable $inizio,
        CarbonImmutable $fine,
        User $utente,
        bool $prova,
        array &$creati,
        array &$saltati,
    ): void {
        $base = [
            'piano_id' => $piano->id,
            'area' => $piano->area?->name,
            'lavorazione' => $piano->workType?->name,
        ];

        // Un'area eliminata, prevista o dismessa non e' (o non e' piu')
        // patrimonio su cui si lavora: il piano resta ma non genera, e lo dice
        if ($piano->area === null) {
            $saltati[] = [...$base, 'mese' => null, 'motivo' => 'Area eliminata: il piano non genera.'];

            return;
        }
        if (in_array($piano->area->status, ['planned', 'dismissed'], true)) {
            $etichetta = $piano->area->status === 'planned' ? 'prevista' : 'dismessa';
            $saltati[] = [...$base, 'mese' => null, 'motivo' => "Area {$etichetta}: il piano non genera."];

            return;
        }
        if ($piano->workType === null) {
            $saltati[] = [...$base, 'mese' => null, 'motivo' => 'Lavorazione eliminata: il piano non genera.'];

            return;
        }

        // Il committente si ricava dalla catena area > localita' > sede:
        // l'ordine generato deve nascere gia' intestato come uno fatto a mano
        $sede = $piano->area->locality?->site;
        if ($sede?->client === null) {
            $saltati[] = [...$base, 'mese' => null, 'motivo' => 'Committente non ricavabile dalla catena del territorio.'];

            return;
        }

        // La squadra di preferenza si applica solo se ancora ammissibile
        // (stessa regola degli ordini, blocco 12): altrimenti l'ordine nasce
        // senza squadra e l'avvertenza lo dice, perche' il lavoro e' dovuto
        // comunque e in agenda la squadra si assegna in un clic
        $squadraId = $piano->team_id;
        $avvertenza = null;
        if ($piano->team_id !== null && $piano->team === null) {
            $squadraId = null;
            $avvertenza = 'Squadra del piano eliminata: ordine senza squadra.';
        } elseif ($piano->team_id !== null) {
            $motivo = ImpresaDelCommittente::motivoEsclusione($piano->team, $sede->client->id);
            if ($motivo !== null) {
                $squadraId = null;
                $avvertenza = 'Squadra del piano non ammissibile: ordine senza squadra. '.$motivo;
            }
        }

        [$scadenze, $prossima] = $this->scadenzeNelPeriodo($piano, $inizio, $fine);

        if ($scadenze === null) {
            $saltati[] = [...$base, 'mese' => null,
                'motivo' => 'Ricorrenza non calcolabile (riferimento troppo lontano o finestra irraggiungibile).'];

            return;
        }
        if ($scadenze === []) {
            $saltati[] = [...$base, 'mese' => null,
                'motivo' => 'Nessuna scadenza nel periodo.'.($prossima !== null ? ' La prossima cade in '.$prossima->format('Y-m').'.' : '')];

            return;
        }

        // Scadenze gia' coperte da un ordine generato in un giro precedente
        // (anche annullato: annullarlo e' stata una decisione, non si
        // ripropone; un ordine eliminato invece torna generabile)
        $giaGenerati = WorkOrder::query()
            ->where('origin', 'maintenance_plan')
            ->where('origin_id', $piano->id)
            ->whereIn('plan_month', array_map(fn ($m) => $m->toDateString(), $scadenze))
            ->pluck('code', 'plan_month');

        foreach ($scadenze as $mese) {
            $esistente = $giaGenerati[$mese->toDateString()] ?? null;
            if ($esistente !== null) {
                $saltati[] = [...$base, 'mese' => $mese->format('Y-m'),
                    'motivo' => "Gia' generato per questa scadenza ({$esistente})."];

                continue;
            }

            $codice = null;
            if (! $prova) {
                $ordine = WorkOrder::create([
                    'tenant_id' => $piano->tenant_id,
                    'code' => WorkOrder::nextCode($piano->tenant_id),
                    'client_id' => $sede->client->id,
                    'site_id' => $sede->id,
                    'area_id' => $piano->area_id,
                    'work_type_id' => $piano->work_type_id,
                    'title' => "{$piano->workType->name} - {$piano->area->name} (dal piano)",
                    'description' => $piano->notes,
                    'status' => 'planned',
                    'origin' => 'maintenance_plan',
                    'origin_id' => $piano->id,
                    'plan_month' => $mese->toDateString(),
                    // Primo del mese di scadenza: la data vera si aggiusta
                    // poi in agenda, l'importante e' comparire nel mese giusto
                    'planned_start' => $mese->toDateString(),
                    'team_id' => $squadraId,
                    'created_by' => $utente->id,
                    'updated_by' => $utente->id,
                ]);
                $codice = $ordine->code;
            }

            $creati[] = [...$base, 'mese' => $mese->format('Y-m'), 'codice' => $codice,
                'squadra' => $squadraId !== null ? $piano->team?->name : null,
                'avvertenza' => $avvertenza];
        }
    }

    /**
     * Le scadenze del piano dentro il periodo, piu' la prima oltre la fine
     * (per dire "la prossima cade in..." quando nel periodo non c'e' nulla).
     *
     * Il cammino: dal riferimento (ultimo ordine esistente di area e
     * lavorazione) si avanza di interval_months; un mese fuori finestra
     * slitta avanti di un mese alla volta fino alla stagione giusta. Senza
     * riferimento si parte dal primo mese del periodo.
     *
     * @return array{0: ?list<CarbonImmutable>, 1: ?CarbonImmutable} scadenze
     *         (null = cammino impossibile) e prossima oltre il periodo
     */
    private function scadenzeNelPeriodo(MaintenancePlan $piano, CarbonImmutable $inizio, CarbonImmutable $fine): array
    {
        $ultimo = $this->riferimento($piano);

        $cursore = $ultimo !== null
            ? $ultimo->startOfMonth()->addMonths($piano->interval_months)
            : $inizio;

        $scadenze = [];
        $prossima = null;
        for ($passi = 0; $passi < self::MASSIMO_PASSI; $passi++) {
            if (! $piano->meseInFinestra((int) $cursore->month)) {
                // Fuori stagione: il lavoro aspetta il primo mese di finestra
                $cursore = $cursore->addMonth();

                continue;
            }
            if ($cursore->greaterThan($fine)) {
                $prossima = $cursore;

                return [$scadenze, $prossima];
            }
            if ($cursore->greaterThanOrEqualTo($inizio)) {
                $scadenze[] = $cursore;
            }
            $cursore = $cursore->addMonths($piano->interval_months);
        }

        // Troppi passi (riferimento antichissimo con intervallo corto, o
        // finestra mai raggiungibile): meglio dirlo che girare a vuoto
        return [null, null];
    }

    /**
     * L'ultima volta che quel lavoro e' stato fatto (o messo in programma)
     * su quell'area: qualunque origine, non solo dai piani, perche' una
     * potatura ordinata a mano azzera comunque il ciclo. Gli annullati non
     * contano: un lavoro annullato non e' stato fatto.
     */
    private function riferimento(MaintenancePlan $piano): ?CarbonImmutable
    {
        $riga = WorkOrder::query()
            ->where('area_id', $piano->area_id)
            ->where('work_type_id', $piano->work_type_id)
            ->where('status', '<>', 'cancelled')
            ->selectRaw('MAX(COALESCE(planned_start, completed_at::date, created_at::date)) AS ultimo')
            ->value('ultimo');

        return $riga !== null ? CarbonImmutable::parse($riga) : null;
    }
}
