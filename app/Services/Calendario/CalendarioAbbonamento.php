<?php

namespace App\Services\Calendario;

use App\Models\Certificate;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Inspections\InspectionDeadlines;
use App\Support\AssetStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Il contenuto del calendario da abbonamento: agenda dei lavori e scadenze
 * (ricontrolli VTA, patentini e certificati) dell'utente identificato dal
 * gettone. Ogni sezione compare solo se l'utente ha il permesso della
 * pagina corrispondente: il feed non deve mostrare più di quanto si vede
 * nel gestionale.
 *
 * Chi chiama deve avere già impostato l'utente autenticato e il team
 * spatie (lo fa CalendarioFeedController): TenantScope e i controlli di
 * permesso contano su questo.
 */
class CalendarioAbbonamento
{
    /**
     * Finestra pubblicata: i calendari esterni riscaricano tutto il file a
     * ogni aggiornamento, spedire l'archivio intero sarebbe solo zavorra.
     * 60 giorni indietro coprono le scadenze arretrate ancora da sanare,
     * 400 avanti l'anno di programmazione.
     */
    private const GIORNI_INDIETRO = 60;

    private const GIORNI_AVANTI = 400;

    /**
     * Oltre questa soglia i ricontrolli VTA dello stesso giorno diventano un
     * evento unico: un Comune con centinaia di ricontrolli alla stessa data
     * sommergerebbe il telefono di eventi identici.
     */
    private const VTA_PER_GIORNO = 10;

    /** Nel corpo dell'evento raggruppato si elencano al massimo questi codici. */
    private const VTA_CODICI_ELENCATI = 30;

    public function ics(User $user): string
    {
        $oggi = Carbon::now('Europe/Rome')->startOfDay();
        $da = $oggi->copy()->subDays(self::GIORNI_INDIETRO)->toDateString();
        $a = $oggi->copy()->addDays(self::GIORNI_AVANTI)->toDateString();

        // DTSTAMP unico per tutto il file (istante di generazione, in UTC)
        $dtstamp = Carbon::now('UTC')->format('Ymd\THis\Z');

        $eventi = [];
        if ($user->can('works.view')) {
            $eventi = [...$eventi, ...$this->lavori($user, $da, $a, $dtstamp)];
            // I patentini stanno sulla pagina Patentini, protetta da works.view
            $eventi = [...$eventi, ...$this->patentini($user->tenant_id, $da, $a, $dtstamp)];
            // Lo scadenzario delle ispezioni ricorrenti vive sotto lo stesso
            // permesso (InspectionController::deadlines)
            $eventi = [...$eventi, ...$this->ispezioni($da, $a, $dtstamp)];
        }
        if ($user->can('assets.view')) {
            $eventi = [...$eventi, ...$this->ricontrolliVta($user->tenant_id, $da, $a, $dtstamp)];
        }

        return Ics::calendario($eventi, 'Verde - agenda e scadenze');
    }

    /** @return list<string> */
    private function lavori(User $user, string $da, string $a, string $dtstamp): array
    {
        // Il tenant è già garantito da TenantScope (l'utente è impostato),
        // ma un feed raggiungibile senza sessione merita la cintura e le
        // bretelle: il filtro esplicito resta anche se lo scope cambiasse
        $query = WorkOrder::query()
            ->where('work_orders.tenant_id', $user->tenant_id)
            ->with(['client:id,name', 'area:id,name', 'team:id,name', 'assignee:id,name']);

        if ($user->can('works.manage')) {
            // Chi gestisce vede tutta l'agenda; chiusi e annullati non sono
            // impegni e resterebbero sul telefono come lavoro da fare
            $query->whereNotIn('status', ['completed', 'cancelled']);
        } else {
            // Un operatore vede i SUOI lavori: stessa regola dell'app di
            // campo (assegnati a lui o a una sua squadra), scritta una volta
            // sola nello scope
            $query->visibleInField($user);
        }

        // Un ordine entra se il periodo pianificato o il termine cadono in
        // finestra; senza date non è un impegno di calendario
        $query->where(function ($w) use ($da, $a) {
            $w->where(fn ($p) => $p->whereNotNull('planned_start')
                ->whereDate('planned_start', '<=', $a)
                ->whereRaw('COALESCE(planned_end, planned_start) >= ?', [$da]))
                ->orWhere(fn ($d) => $d->whereNotNull('due_at')
                    ->whereBetween(DB::raw("(due_at AT TIME ZONE 'Europe/Rome')::date"), [$da, $a]));
        });

        $eventi = [];
        foreach ($query->orderBy('planned_start')->orderBy('code')->cursor() as $ordine) {
            $descrizione = $this->descrizioneLavoro($ordine);
            $titolo = $ordine->area?->name
                ? "{$ordine->title} - {$ordine->area->name} ({$ordine->code})"
                : "{$ordine->title} ({$ordine->code})";

            if ($ordine->planned_start !== null) {
                $eventi[] = Ics::evento([
                    'uid' => "lavoro-{$ordine->id}@webgis",
                    'inizio' => $ordine->planned_start->toDateString(),
                    'fine' => $ordine->planned_end?->toDateString(),
                    'titolo' => $titolo,
                    'descrizione' => $descrizione,
                ], $dtstamp);
            }

            // Il termine ultimo è un impegno a sé: può cadere lontano dai
            // giorni pianificati e va visto anche se la pianificazione manca
            if ($ordine->due_at !== null) {
                $eventi[] = Ics::evento([
                    'uid' => "lavoro-termine-{$ordine->id}@webgis",
                    'inizio' => $ordine->due_at->timezone('Europe/Rome')->toDateString(),
                    'titolo' => "Termine {$ordine->code} - {$ordine->title}",
                    'descrizione' => $descrizione,
                ], $dtstamp);
            }
        }

        return $eventi;
    }

    private function descrizioneLavoro(WorkOrder $ordine): string
    {
        $righe = array_filter([
            'Stato: '.(WorkOrder::STATUS_LABELS[$ordine->status] ?? $ordine->status),
            $ordine->client?->name ? 'Committente: '.$ordine->client->name : null,
            $ordine->area?->name ? 'Area: '.$ordine->area->name : null,
            $ordine->team?->name ? 'Squadra: '.$ordine->team->name : null,
            $ordine->assignee?->name ? 'Responsabile: '.$ordine->assignee->name : null,
        ]);

        return implode("\n", $righe);
    }

    /** @return list<string> */
    private function ricontrolliVta(string $tenantId, string $da, string $a, string $dtstamp): array
    {
        // Ultima valutazione per albero, come nello scadenzario VTA
        // (VtaDashboardController): conta solo la più recente, e gli alberi
        // in archivio o abbattuti non hanno ricontrolli da programmare
        $fuoriArchivio = AssetStatus::sqlArchivio();
        $righe = DB::select(<<<SQL
            SELECT latest.tree_id, latest.next_check_due, a.census_code, t.species, t.genus
            FROM (
              SELECT DISTINCT ON (ta.tree_id) ta.tree_id, ta.next_check_due
              FROM tree_assessments ta
              WHERE ta.tenant_id = ? AND ta.deleted_at IS NULL
              ORDER BY ta.tree_id, ta.assessed_on DESC, ta.created_at DESC
            ) latest
            JOIN assets a ON a.id = latest.tree_id AND a.deleted_at IS NULL
                         AND a.status NOT IN ({$fuoriArchivio})
            JOIN trees t ON t.asset_id = latest.tree_id AND t.removed_on IS NULL
            WHERE latest.next_check_due BETWEEN ? AND ?
            ORDER BY latest.next_check_due, a.census_code
            SQL, [$tenantId, $da, $a]);

        $eventi = [];
        foreach (collect($righe)->groupBy('next_check_due') as $giorno => $gruppo) {
            $giorno = Carbon::parse($giorno)->toDateString();

            if ($gruppo->count() <= self::VTA_PER_GIORNO) {
                foreach ($gruppo as $riga) {
                    $specie = $riga->species ?: $riga->genus;
                    $eventi[] = Ics::evento([
                        'uid' => "vta-{$riga->tree_id}@webgis",
                        'inizio' => $giorno,
                        'titolo' => 'Ricontrollo VTA - '.($riga->census_code ?? 'albero senza codice'),
                        'descrizione' => $specie ? "Specie: {$specie}" : null,
                    ], $dtstamp);
                }

                continue;
            }

            // Giornata affollata: un solo evento con l'elenco dei codici
            $codici = $gruppo->map(fn ($r) => $r->census_code ?? 'senza codice');
            $elenco = $codici->take(self::VTA_CODICI_ELENCATI)->implode(', ');
            if ($codici->count() > self::VTA_CODICI_ELENCATI) {
                $elenco .= ' e altri '.($codici->count() - self::VTA_CODICI_ELENCATI);
            }
            $eventi[] = Ics::evento([
                'uid' => 'vta-giorno-'.str_replace('-', '', $giorno).'@webgis',
                'inizio' => $giorno,
                'titolo' => "Ricontrolli VTA - {$gruppo->count()} alberi",
                'descrizione' => 'Alberi: '.$elenco,
            ], $dtstamp);
        }

        return $eventi;
    }

    /** @return list<string> */
    private function ispezioni(string $da, string $a, string $dtstamp): array
    {
        // Le ispezioni registrate non hanno una data futura propria: la
        // scadenza del prossimo controllo la calcola InspectionDeadlines
        // (ultima ispezione + periodicità del modello), la stessa logica
        // della pagina Ispezioni e del cruscotto Oggi. Le query dentro
        // compute() passano da TenantScope: l'utente è già impostato
        $eventi = [];
        foreach (app(InspectionDeadlines::class)->compute() as $riga) {
            if ($riga['due_date'] < $da || $riga['due_date'] > $a) {
                continue;
            }
            // UID stabile su modello + bersaglio: quando l'ispezione viene
            // ripetuta l'evento si sposta alla scadenza successiva invece di
            // duplicarsi sul telefono
            $eventi[] = Ics::evento([
                'uid' => "ispezione-{$riga['template_id']}-{$riga['target_id']}@webgis",
                'inizio' => $riga['due_date'],
                'titolo' => "Ispezione {$riga['template_name']} - {$riga['target_label']}",
                'descrizione' => implode("\n", [
                    'Periodicità: ogni '.$riga['frequency_days'].' giorni',
                    'Ultima ispezione: '.Carbon::parse($riga['last_completed_at'])
                        ->timezone('Europe/Rome')->format('d/m/Y'),
                ]),
            ], $dtstamp);
        }

        return $eventi;
    }

    /** @return list<string> */
    private function patentini(string $tenantId, string $da, string $a, string $dtstamp): array
    {
        $eventi = [];
        $certificati = Certificate::query()
            // Filtro esplicito oltre a TenantScope: vedi lavori()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('expires_on')
            ->whereBetween('expires_on', [$da, $a])
            ->orderBy('expires_on')->orderBy('holder_name')
            ->get();

        foreach ($certificati as $certificato) {
            $eventi[] = Ics::evento([
                'uid' => "patentino-{$certificato->id}@webgis",
                'inizio' => $certificato->expires_on->toDateString(),
                'titolo' => "Scadenza {$certificato->title} - {$certificato->holder_name}",
                'descrizione' => implode("\n", array_filter([
                    $certificato->number ? 'Numero: '.$certificato->number : null,
                    $certificato->issued_by ? 'Rilasciato da: '.$certificato->issued_by : null,
                ])),
            ], $dtstamp);
        }

        return $eventi;
    }
}
