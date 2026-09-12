<?php

namespace App\Services\Works;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAsset;
use App\Models\WorkType;
use App\Support\Audit;
use App\Support\AssetStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Dallo scadenzario VTA all'agenda: la data di prossimo controllo diventa un
 * ordine di lavoro "Ricontrollo VTA" sull'albero.
 *
 * Regola di casa (come AzioniMultiple e GeneratorePiani): anteprima ed
 * esecuzione passano DALLO STESSO metodo, con $prova a decidere se scrivere.
 * Mai due percorsi: se divergono, il pre-conteggio mente.
 *
 * Riferimento: l'ultima valutazione di ogni albero (la stessa riga che lo
 * scadenzario mostra). Una valutazione piu' recente sposta la scadenza, e
 * l'ordine nasce da quella: e' l'ultima parola del tecnico che conta.
 *
 * Idempotenza: l'ordine porta origin 'vta_recheck' e origin_id della
 * valutazione. Rilanciare non crea doppioni (indice unico sul DB); l'ordine
 * annullato continua a coprire la sua valutazione, quello eliminato la
 * libera. Le date spostate in agenda non fanno rigenerare nulla: la chiave
 * e' la valutazione, non la data.
 */
class GeneratoreRicontrolliVta
{
    /** Lavorazione usata dagli ordini di ricontrollo, creata alla prima occorrenza. */
    public const CODICE_LAVORAZIONE = 'RIC-VTA';

    /** Tetto per lancio: oltre, la transazione tiene bloccate troppe righe. */
    public const MASSIMO = 500;

    /**
     * @param  list<string>|null  $assetIds  alberi scelti a mano (null = tutti quelli del filtro)
     * @return array{creati: list<array>, saltati: list<array>}
     */
    public function genera(
        ?string $clientId,
        ?array $assetIds,
        CarbonImmutable $entro,
        User $utente,
        bool $prova = false,
    ): array {
        $creati = [];
        $saltati = [];

        DB::transaction(function () use ($clientId, $assetIds, $entro, $utente, $prova, &$creati, &$saltati) {
            // Due generazioni simultanee dello stesso tenant si mettono in
            // fila: la seconda rilegge a lock preso e vede gli ordini della
            // prima, quindi salta invece di violare l'indice unico
            DB::statement(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 42))',
                ["ricontrolli-vta:{$utente->tenant_id}"],
            );

            $righe = $this->candidati($utente->tenant_id, $clientId, $assetIds, $entro);

            // Le valutazioni gia' coperte da un ordine: una lettura sola per
            // tutto il giro, non una per albero
            $coperte = WorkOrder::query()
                ->where('origin', 'vta_recheck')
                ->whereIn('origin_id', $righe->pluck('assessment_id')->filter()->all())
                ->pluck('code', 'origin_id');

            $lavorazione = null;

            foreach ($righe as $riga) {
                $base = [
                    'asset_id' => $riga->asset_id,
                    'codice' => $riga->census_code,
                    'scadenza' => $riga->next_check_due,
                ];

                $motivo = $this->motivoEsclusione($riga, $entro, $coperte);
                if ($motivo !== null) {
                    $saltati[] = [...$base, 'motivo' => $motivo];

                    continue;
                }

                $ordine = null;
                if (! $prova) {
                    // La lavorazione si crea (una volta per tenant) solo
                    // quando si scrive davvero: la prova non lascia tracce
                    $lavorazione ??= $this->lavorazione($utente->tenant_id);

                    $ordine = WorkOrder::create([
                        'tenant_id' => $utente->tenant_id,
                        'code' => WorkOrder::nextCode($utente->tenant_id),
                        'client_id' => $riga->client_id,
                        'site_id' => $riga->site_id,
                        'area_id' => $riga->area_id,
                        'work_type_id' => $lavorazione->id,
                        'title' => 'Ricontrollo VTA - '.($riga->census_code ?? 'albero senza codice'),
                        'description' => $this->descrizione($riga),
                        'status' => 'planned',
                        'origin' => 'vta_recheck',
                        'origin_id' => $riga->assessment_id,
                        // La data della prescrizione: in agenda si sposta,
                        // e spostarla non fa rigenerare l'ordine
                        'planned_start' => $riga->next_check_due,
                        'created_by' => $utente->id,
                        'updated_by' => $utente->id,
                    ]);

                    // L'albero attaccato all'ordine: l'operatore in campo deve
                    // sapere quale pianta andare a guardare. Un albero e' un
                    // "cadauno": la quantita' si scrive solo se l'unita' della
                    // lavorazione e' quella, altrimenti la si lascia al tecnico
                    $aCadauno = $lavorazione->unit === 'cad';
                    WorkOrderAsset::create([
                        'tenant_id' => $utente->tenant_id,
                        'work_order_id' => $ordine->id,
                        'asset_id' => $riga->asset_id,
                        'work_type_id' => $lavorazione->id,
                        'planned_quantity' => $aCadauno ? 1 : null,
                        'unit' => $aCadauno ? $lavorazione->unit : null,
                    ]);
                }

                $creati[] = [...$base, 'ordine' => $ordine?->code];
            }

            // Il registro racconta solo cose successe: niente audit in prova
            if (! $prova) {
                Audit::log('vta.recheck.generated', null, [
                    'entro' => $entro->toDateString(),
                    'creati' => count($creati),
                    'saltati' => count($saltati),
                ]);
            }
        });

        return ['creati' => $creati, 'saltati' => $saltati];
    }

    /**
     * Le righe su cui si decide: un albero per riga con la sua ultima
     * valutazione e la catena del territorio.
     *
     * Con gli alberi scelti a mano si prendono tutti quelli chiesti, anche
     * quelli che non si possono fare: chi ha spuntato venti caselle deve
     * leggere perche' tre sono rimaste fuori. Senza scelta si prendono solo
     * quelli dovuti entro la data: gli altri non sono esclusioni, non
     * riguardano il giro.
     *
     * @param  list<string>|null  $assetIds
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function candidati(string $tenantId, ?string $clientId, ?array $assetIds, CarbonImmutable $entro)
    {
        $archivio = AssetStatus::sqlArchivio();

        $sql = <<<SQL
            SELECT a.id AS asset_id, a.census_code, a.status, a.area_id,
                   ar.status AS area_status,
                   s.id AS site_id, s.client_id,
                   t.removed_on,
                   vta.id AS assessment_id, vta.assessed_on, vta.failure_class,
                   vta.outcome, vta.prescriptions,
                   vta.next_check_due::text AS next_check_due
            FROM assets a
            JOIN trees t ON t.asset_id = a.id
            LEFT JOIN areas ar ON ar.id = a.area_id
            LEFT JOIN localities lc ON lc.id = ar.locality_id
            LEFT JOIN sites s ON s.id = lc.site_id
            LEFT JOIN LATERAL (
              SELECT ta.id, ta.assessed_on, ta.failure_class, ta.outcome,
                     ta.prescriptions, ta.next_check_due
              FROM tree_assessments ta
              WHERE ta.tree_id = a.id AND ta.tenant_id = a.tenant_id AND ta.deleted_at IS NULL
              ORDER BY ta.assessed_on DESC, ta.created_at DESC
              LIMIT 1
            ) vta ON true
            WHERE a.tenant_id = ? AND a.deleted_at IS NULL
            SQL;
        $bindings = [$tenantId];

        if ($assetIds !== null) {
            $segnaposti = implode(',', array_fill(0, count($assetIds), '?'));
            $sql .= " AND a.id IN ({$segnaposti})";
            $bindings = [...$bindings, ...$assetIds];
        } else {
            // Il giro automatico guarda solo il dovuto, e solo su alberi in
            // piedi e schede vive: il resto non e' un'esclusione da spiegare
            $sql .= " AND a.status NOT IN ({$archivio}) AND t.removed_on IS NULL"
                .' AND vta.next_check_due IS NOT NULL AND vta.next_check_due <= ?';
            $bindings[] = $entro->toDateString();
        }

        if ($clientId !== null) {
            $sql .= ' AND s.client_id = ?';
            $bindings[] = $clientId;
        }

        $sql .= ' ORDER BY vta.next_check_due NULLS LAST, a.census_code, a.id';

        return collect(DB::select($sql, $bindings));
    }

    /**
     * Perche' un albero resta fuori. Un motivo solo, il primo che si
     * incontra: l'elenco delle esclusioni deve restare leggibile.
     *
     * @param  \Illuminate\Support\Collection<string, string>  $coperte
     */
    private function motivoEsclusione(object $riga, CarbonImmutable $entro, $coperte): ?string
    {
        if (AssetStatus::inArchivio($riga->status)) {
            return 'In archivio ('.AssetStatus::label($riga->status).'): non si programmano ricontrolli.';
        }
        if ($riga->removed_on !== null) {
            return 'Albero abbattuto: non si programmano ricontrolli.';
        }
        if ($riga->assessment_id === null) {
            return 'Nessuna valutazione registrata: prima si valuta, poi si ricontrolla.';
        }
        if ($riga->next_check_due === null) {
            return "L'ultima valutazione non fissa una data di ricontrollo.";
        }
        if ($riga->next_check_due > $entro->toDateString()) {
            return 'Ricontrollo previsto il '.$this->giorno($riga->next_check_due).': oltre la data scelta.';
        }
        if ($riga->client_id === null) {
            return 'Committente non ricavabile dalla catena del territorio.';
        }
        if (in_array($riga->area_status, ['planned', 'dismissed'], true)) {
            $etichetta = $riga->area_status === 'planned' ? 'prevista' : 'dismessa';

            return "Area {$etichetta}: non ci si lavora.";
        }

        $esistente = $coperte[$riga->assessment_id] ?? null;
        if ($esistente !== null) {
            return "Ordine di ricontrollo gia' presente ({$esistente}).";
        }

        return null;
    }

    /** Nel corpo dell'ordine si porta il perche': classe, esito e prescrizioni. */
    private function descrizione(object $riga): string
    {
        $classe = $riga->failure_class !== null ? "classe {$riga->failure_class}" : 'classe non assegnata';
        $righe = ['Ricontrollo previsto dalla valutazione del '.$this->giorno($riga->assessed_on)." ({$classe})."];

        if (filled($riga->prescriptions)) {
            $righe[] = 'Prescrizioni della valutazione: '.$riga->prescriptions;
        }

        return implode("\n", $righe);
    }

    private function giorno(?string $data): string
    {
        return $data !== null ? CarbonImmutable::parse($data)->format('d/m/Y') : '—';
    }

    /**
     * La lavorazione "Ricontrollo VTA" del tenant, creata alla prima
     * generazione: il programma non pretende che qualcuno l'abbia gia'
     * messa a listino, ma se c'e' usa quella (anche rinominata).
     */
    private function lavorazione(string $tenantId): WorkType
    {
        $esistente = WorkType::query()->where('code', self::CODICE_LAVORAZIONE)->first();
        if ($esistente !== null) {
            return $esistente;
        }

        return WorkType::create([
            'tenant_id' => $tenantId,
            'code' => self::CODICE_LAVORAZIONE,
            'name' => 'Ricontrollo VTA',
            'category' => 'verde verticale',
            'unit' => 'cad',
            'applicable_geometry' => 'P',
        ]);
    }
}
