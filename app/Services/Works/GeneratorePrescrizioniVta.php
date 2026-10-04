<?php

namespace App\Services\Works;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAsset;
use App\Models\WorkType;
use App\Support\Audit;
use App\Support\AssetStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dalle prescrizioni di una valutazione VTA all'agenda (punto 7 dell'elenco
 * del committente del 04/10/2026): l'intervento prescritto (rimonda del
 * secco, riduzione della chioma, trattamento endoterapico...) diventa un
 * ordine di lavoro sull'albero, con la data entro cui farlo.
 *
 * Stesse regole di casa di GeneratoreRicontrolliVta: anteprima ed esecuzione
 * dallo stesso metodo ($prova); il riferimento e' l'ULTIMA valutazione di
 * ogni albero (una valutazione piu' recente senza prescrizioni chiude quelle
 * vecchie: conta l'ultima parola del tecnico); un ordine per valutazione,
 * garantito dall'indice unico (origin 'vta_prescription', origin_id della
 * valutazione); l'ordine annullato copre comunque la sua valutazione.
 */
class GeneratorePrescrizioniVta
{
    /** Lavorazione generica, quando nessuna lavorazione del listino si riconosce nel testo. */
    public const CODICE_LAVORAZIONE = 'PRE-VTA';

    public const MASSIMO = 500;

    /** Parole del testo che portano a una famiglia di lavorazioni del listino (cercata nel nome). */
    private const FAMIGLIE = [
        ['parole' => ['abbatt'], 'nome' => 'abbatt'],
        ['parole' => ['potat', 'rimond', 'riduz', 'alleggeri', 'spalcat', 'contenim'], 'nome' => 'potat'],
        ['parole' => ['trattament', 'endoterap', 'iniezion', 'irroraz'], 'nome' => 'trattament'],
        ['parole' => ['consolidam', 'tirant', 'ancoragg'], 'nome' => 'consolidam'],
    ];

    /**
     * @param  list<string>|null  $assessmentIds  valutazioni scelte a mano (null = tutte le prescrizioni aperte)
     * @return array{creati: list<array>, saltati: list<array>}
     */
    public function genera(?string $clientId, ?array $assessmentIds, User $utente, bool $prova = false): array
    {
        $creati = [];
        $saltati = [];

        DB::transaction(function () use ($clientId, $assessmentIds, $utente, $prova, &$creati, &$saltati) {
            DB::statement(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 42))',
                ["prescrizioni-vta:{$utente->tenant_id}"],
            );

            // A mano si prendono tutte le valutazioni chieste (anche quelle
            // che non si possono fare, per spiegarlo); in automatico solo le aperte
            $righe = self::righe($utente->tenant_id, $clientId, $assessmentIds, soloAperte: $assessmentIds === null);
            $lavorazioni = null;

            foreach ($righe as $riga) {
                $base = [
                    'asset_id' => $riga->asset_id,
                    'assessment_id' => $riga->assessment_id,
                    'codice' => $riga->census_code,
                    'entro' => $riga->prescriptions_due_on,
                ];
                $motivo = $this->motivoEsclusione($riga);
                if ($motivo !== null) {
                    $saltati[] = [...$base, 'motivo' => $motivo];

                    continue;
                }

                $ordine = null;
                $lavorazione = null;
                if (! $prova) {
                    $lavorazioni ??= WorkType::query()->where('is_active', true)->get();
                    $lavorazione = $this->lavorazionePer((string) $riga->prescriptions, $lavorazioni, $utente->tenant_id);
                    if (! $lavorazioni->contains('id', $lavorazione->id)) {
                        $lavorazioni->push($lavorazione);
                    }

                    $ordine = WorkOrder::create([
                        'tenant_id' => $utente->tenant_id,
                        'code' => WorkOrder::nextCode($utente->tenant_id),
                        'client_id' => $riga->client_id,
                        'site_id' => $riga->site_id,
                        'area_id' => $riga->area_id,
                        'work_type_id' => $lavorazione->id,
                        'title' => 'Prescrizione VTA - '.($riga->census_code ?? 'albero senza codice'),
                        'description' => $this->descrizione($riga),
                        'status' => 'planned',
                        'origin' => 'vta_prescription',
                        'origin_id' => $riga->assessment_id,
                        // La data entro cui il tecnico ha chiesto l'intervento:
                        // in agenda si sposta, e spostarla non rigenera nulla
                        'planned_start' => $riga->prescriptions_due_on,
                        'planned_end' => $riga->prescriptions_due_on,
                        'created_by' => $utente->id,
                        'updated_by' => $utente->id,
                    ]);

                    $aCadauno = $lavorazione->unit === 'cad';
                    WorkOrderAsset::create([
                        'tenant_id' => $utente->tenant_id,
                        'work_order_id' => $ordine->id,
                        'asset_id' => $riga->asset_id,
                        'work_type_id' => $lavorazione->id,
                        'planned_quantity' => $aCadauno ? 1 : null,
                        'unit' => $aCadauno ? $lavorazione->unit : null,
                        // Il "che cosa fare" su questo albero e' la prescrizione stessa
                        'notes' => trim((string) $riga->prescriptions),
                    ]);
                }

                $creati[] = [...$base, 'ordine' => $ordine?->code, 'ordine_id' => $ordine?->id, 'lavorazione' => $lavorazione?->name];
            }

            if (! $prova) {
                Audit::log('vta.prescription.generated', null, ['creati' => count($creati), 'saltati' => count($saltati)]);
            }
        });

        return ['creati' => $creati, 'saltati' => $saltati];
    }

    /**
     * Le prescrizioni dell'organizzazione, un albero per riga con la sua
     * ULTIMA valutazione (solo se ha prescrizioni scritte) e l'eventuale
     * ordine che ne e' nato. E' la stessa lettura dell'elenco in pagina, del
     * generatore e del cruscotto Oggi: i numeri devono tornare.
     *
     * @param  list<string>|null  $assessmentIds
     * @return Collection<int, object>
     */
    public static function righe(string $tenantId, ?string $clientId = null, ?array $assessmentIds = null, bool $soloAperte = false): Collection
    {
        $archivio = AssetStatus::sqlArchivio();
        $sql = <<<SQL
            SELECT a.id AS asset_id, a.census_code, a.status, a.area_id,
                   ar.status AS area_status, ar.name AS area_name,
                   s.id AS site_id, s.client_id, c.name AS client_name,
                   t.removed_on, t.species, t.common_name,
                   vta.id AS assessment_id, vta.assessed_on::text AS assessed_on, vta.failure_class, vta.outcome,
                   vta.prescriptions, vta.prescriptions_due_on::text AS prescriptions_due_on,
                   COALESCE(NULLIF(vta.assessor_external, ''), u.name) AS assessor_name,
                   wo.id AS work_order_id, wo.code AS work_order_code, wo.status AS work_order_status
            FROM assets a
            JOIN trees t ON t.asset_id = a.id
            LEFT JOIN areas ar ON ar.id = a.area_id
            LEFT JOIN localities lc ON lc.id = ar.locality_id
            LEFT JOIN sites s ON s.id = lc.site_id
            LEFT JOIN clients c ON c.id = s.client_id
            JOIN LATERAL (
              SELECT ta.id, ta.assessed_on, ta.failure_class, ta.outcome, ta.prescriptions,
                     ta.prescriptions_due_on, ta.assessor_external, ta.assessor_id
              FROM tree_assessments ta
              WHERE ta.tree_id = a.id AND ta.tenant_id = a.tenant_id AND ta.deleted_at IS NULL
              ORDER BY ta.assessed_on DESC, ta.created_at DESC
              LIMIT 1
            ) vta ON true
            LEFT JOIN users u ON u.id = vta.assessor_id
            LEFT JOIN work_orders wo ON wo.origin = 'vta_prescription' AND wo.origin_id = vta.id AND wo.deleted_at IS NULL
            WHERE a.tenant_id = ? AND a.deleted_at IS NULL
              AND vta.prescriptions IS NOT NULL AND btrim(vta.prescriptions) <> ''
            SQL;
        $bindings = [$tenantId];

        if ($assessmentIds !== null) {
            $segnaposti = implode(',', array_fill(0, max(1, count($assessmentIds)), '?'));
            $sql .= " AND vta.id IN ({$segnaposti})";
            $bindings = [...$bindings, ...($assessmentIds ?: ['00000000-0000-0000-0000-000000000000'])];
        } elseif ($soloAperte) {
            $sql .= " AND a.status NOT IN ({$archivio}) AND t.removed_on IS NULL AND wo.id IS NULL";
        }
        if ($clientId !== null) {
            $sql .= ' AND s.client_id = ?';
            $bindings[] = $clientId;
        }
        $sql .= ' ORDER BY vta.prescriptions_due_on NULLS LAST, vta.assessed_on, a.census_code LIMIT '.self::MASSIMO;

        return collect(DB::select($sql, $bindings));
    }

    private function motivoEsclusione(object $riga): ?string
    {
        if ($riga->work_order_code) {
            return $riga->work_order_status === 'cancelled'
                ? "coperta dall'ordine {$riga->work_order_code}, annullato: per rifarlo elimina quell'ordine"
                : "già in agenda con {$riga->work_order_code}";
        }
        if ($riga->removed_on) {
            return 'albero rimosso il '.Carbon::parse($riga->removed_on)->format('d/m/Y');
        }
        if (AssetStatus::inArchivio((string) $riga->status)) {
            return 'scheda in archivio ('.AssetStatus::label((string) $riga->status).')';
        }

        return null;
    }

    private function descrizione(object $riga): string
    {
        $righe = [trim((string) $riga->prescriptions), ''];
        $righe[] = 'Dalla valutazione di stabilità del '.Carbon::parse($riga->assessed_on)->format('d/m/Y')
            .($riga->failure_class ? ' (classe '.$riga->failure_class.')' : '')
            .($riga->assessor_name ? ', rilevatore '.$riga->assessor_name : '').'.';
        if ($riga->prescriptions_due_on) {
            $righe[] = 'Da eseguire entro il '.Carbon::parse($riga->prescriptions_due_on)->format('d/m/Y').'.';
        }

        return implode("\n", $righe);
    }

    /**
     * La lavorazione del listino che il testo della prescrizione nomina (il
     * nome piu' lungo che compare), altrimenti la famiglia riconosciuta dalle
     * parole, altrimenti quella generica, creata una volta per organizzazione.
     *
     * @param  Collection<int, WorkType>  $lavorazioni
     */
    private function lavorazionePer(string $testo, Collection $lavorazioni, string $tenantId): WorkType
    {
        $t = mb_strtolower($testo);
        $perNome = $lavorazioni
            ->filter(fn (WorkType $w) => mb_strlen((string) $w->name) >= 4 && str_contains($t, mb_strtolower((string) $w->name)))
            ->sortByDesc(fn (WorkType $w) => mb_strlen((string) $w->name))->first();
        if ($perNome) {
            return $perNome;
        }
        foreach (self::FAMIGLIE as $famiglia) {
            foreach ($famiglia['parole'] as $parola) {
                if (str_contains($t, $parola)) {
                    $trovata = $lavorazioni->first(fn (WorkType $w) => str_contains(mb_strtolower((string) $w->name), $famiglia['nome']));
                    if ($trovata) {
                        return $trovata;
                    }
                }
            }
        }

        return WorkType::query()->where('code', self::CODICE_LAVORAZIONE)->first() ?? WorkType::create([
            'tenant_id' => $tenantId,
            'code' => self::CODICE_LAVORAZIONE,
            'name' => 'Intervento da prescrizione VTA',
            'category' => 'verde verticale',
            'unit' => 'cad',
            'applicable_geometry' => 'P',
        ]);
    }
}
