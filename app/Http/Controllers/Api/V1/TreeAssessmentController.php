<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\TreeAssessment;
use App\Models\ClientAlert;
use App\Services\Vta\AvvisoCommittente;
use App\Services\Vta\BersagliProposti;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TreeAssessmentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:assets.view', only: ['index', 'bersagliProposti']),
            new Middleware('can:assets.update', only: ['store', 'update', 'rientro']),
            new Middleware('can:assets.delete', only: ['destroy']),
            new Middleware('can:assets.update', only: ['valida']),
            // Gli intervalli valgono per tutta l'organizzazione: li tocca
            // l'amministratore, dalla pagina Utenti come le altre impostazioni
            new Middleware('can:users.manage', only: ['intervalli', 'aggiornaIntervalli']),
        ];
    }

    /** Mesi al ricontrollo per classe: quelli in uso e i predefiniti. */
    public function intervalli(Request $request): JsonResponse
    {
        $settings = \App\Models\Organization::find($request->user()->tenant_id)?->settings ?? [];
        $personalizzati = $settings['vta_recheck_months'] ?? [];

        return response()->json([
            'data' => array_replace(TreeAssessment::DEFAULT_RECHECK_MONTHS, $personalizzati),
            'defaults' => TreeAssessment::DEFAULT_RECHECK_MONTHS,
            'personalizzato' => $personalizzati !== [],
        ]);
    }

    /**
     * Cambia i mesi al ricontrollo per classe, o torna ai predefiniti.
     * Vale per le prossime valutazioni: le date gia' assegnate non cambiano.
     * La classe D resta senza data: l'esito e' l'abbattimento, non un
     * nuovo appuntamento.
     */
    public function aggiornaIntervalli(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ripristina' => ['sometimes', 'boolean'],
            // Rule::requiredIf e non required_without: "ripristina: false"
            // deve pretendere i mesi (422), non passare e poi esplodere
            'mesi' => [Rule::requiredIf(fn () => ! $request->boolean('ripristina')), 'array'],
            'mesi.A' => ['required_with:mesi', 'integer', 'min:1', 'max:240'],
            'mesi.B' => ['required_with:mesi', 'integer', 'min:1', 'max:240'],
            'mesi.C' => ['required_with:mesi', 'integer', 'min:1', 'max:240'],
            'mesi.C/D' => ['required_with:mesi', 'integer', 'min:1', 'max:240'],
        ], [], [
            'mesi.A' => 'mesi per la classe A',
            'mesi.B' => 'mesi per la classe B',
            'mesi.C' => 'mesi per la classe C',
            'mesi.C/D' => 'mesi per la classe C/D',
        ]);

        // Sotto lock e rileggendo dentro la transazione: nella stessa colonna
        // "settings" vivono il contatore dei protocolli e le altre
        // impostazioni, che non devono sparire per un salvataggio incrociato
        DB::transaction(function () use ($request, $data) {
            $organization = \App\Models\Organization::query()
                ->lockForUpdate()
                ->findOrFail($request->user()->tenant_id);

            $settings = $organization->settings ?? [];
            if ($request->boolean('ripristina')) {
                unset($settings['vta_recheck_months']);
            } else {
                $settings['vta_recheck_months'] = [
                    'A' => (int) $data['mesi']['A'],
                    'B' => (int) $data['mesi']['B'],
                    'C' => (int) $data['mesi']['C'],
                    'C/D' => (int) $data['mesi']['C/D'],
                ];
            }
            $organization->forceFill(['settings' => $settings])->save();
        });

        Audit::log('vta.intervalli_updated', null, [
            'ripristina' => $request->boolean('ripristina'),
            'mesi' => $data['mesi'] ?? null,
        ]);

        return $this->intervalli($request);
    }

    public function index(string $assetId): JsonResponse
    {
        $asset = Asset::with('tree')->findOrFail($assetId);
        abort_if($asset->tree === null, 404, 'Questo elemento non è un albero.');

        return response()->json([
            'data' => $asset->tree->assessments()
                ->with(['assessor:id,name', 'validator:id,name', 'avviso.area:id,name', 'avviso.acknowledger:id,name', 'avviso.resolver:id,name'])
                ->withCount('instrumentalAnalyses')
                ->orderByDesc('assessed_on')
                ->get(),
        ]);
    }

    /**
     * I bersagli che il censimento propone per la valutazione: le aree in cui
     * l'albero sta e gli elementi censiti nel suo raggio di caduta
     * (App\Services\Vta\BersagliProposti). `raggio` in metri per allargarlo o stringerlo.
     */
    public function bersagliProposti(Request $request, string $assetId, BersagliProposti $proposte): JsonResponse
    {
        $dati = $request->validate([
            'raggio' => ['nullable', 'numeric', 'min:1', 'max:'.BersagliProposti::RAGGIO_MASSIMO],
        ]);
        $asset = Asset::with('tree')->findOrFail($assetId);

        return response()->json(['data' => [
            ...$proposte->per($asset, isset($dati['raggio']) ? (float) $dati['raggio'] : null),
            // Il committente e i suoi indirizzi: la scheda VTA li mostra prima di mandare l'avviso
            'committente' => AvvisoCommittente::committentePer($asset),
        ]]);
    }

    public function store(Request $request, string $assetId): JsonResponse
    {
        $asset = Asset::with('tree')->findOrFail($assetId);
        if ($asset->tree === null) {
            throw ValidationException::withMessages([
                'asset' => 'Le valutazioni di stabilità si registrano solo sugli alberi.',
            ]);
        }

        $data = $request->validate($this->rules(), $this->messages());
        $avviso = $this->avvisoRichiesto($data, $asset);

        if (isset($data['survey']['difetti'])) {
            // Solo le parti previste dalla scheda: niente chiavi arbitrarie
            $data['survey']['difetti'] = array_intersect_key(
                $data['survey']['difetti'],
                array_flip(TreeAssessment::BODY_PARTS),
            );
        }

        // Scadenzario automatico: se il tecnico non indica il ricontrollo,
        // lo deriva dalla classe di propensione al cedimento (configurabile per tenant)
        if (empty($data['next_check_due']) && ! empty($data['failure_class'])) {
            $months = $this->recheckMonths($request)[$data['failure_class']] ?? null;
            if ($months !== null) {
                $data['next_check_due'] = \Illuminate\Support\Carbon::parse($data['assessed_on'])
                    ->addMonths($months)->toDateString();
            }
        }

        $assessment = TreeAssessment::create([
            ...$data,
            'tree_id' => $asset->tree->asset_id,
            'assessor_id' => $request->user()->id,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        Audit::log('vta.created', $assessment, [
            'asset_id' => $asset->id,
            'failure_class' => $assessment->failure_class,
        ]);

        // L'avviso al committente parte dopo che la valutazione e' salva: la
        // valutazione non si perde se la posta non risponde
        if ($avviso !== null) {
            app(AvvisoCommittente::class)->invia($assessment, $asset, $request->user(), $avviso['testo'], $avviso['area_id']);
        }

        // refresh: version e i valori con default lato database servono
        // subito a chi correggerà la valutazione (blocco ottimistico)
        return response()->json(['data' => $assessment->refresh()->load('assessor:id,name', 'avviso.area:id,name')], 201);
    }

    /**
     * Correzione di una valutazione già registrata: un refuso nella perizia
     * non deve costringere a cancellare tutto (le analisi strumentali sono
     * agganciate alla valutazione e sparirebbero con lei).
     *
     * Se la perizia era già stata emessa, il protocollo viene azzerato: il
     * documento corretto esce con un numero e una data nuovi, così il numero
     * già consegnato non finisce su un contenuto diverso.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            ...$this->rules(perTutti: false),
            'version' => ['sometimes', 'integer'],
        ], $this->messages());
        $avvisoRichiesto = $data['avviso_committente'] ?? null;
        unset($data['avviso_committente']);
        $avviso = null;

        $assessment = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $id, $data, $avvisoRichiesto, &$avviso) {
            $assessment = TreeAssessment::query()->lockForUpdate()->findOrFail($id);
            // Un avviso per valutazione: se c'e' gia', la correzione non lo rimanda
            if (($avvisoRichiesto['attivo'] ?? false) && $assessment->avviso()->doesntExist()) {
                $asset = Asset::query()->findOrFail($assessment->tree_id);
                $avviso = $this->avvisoRichiesto(['avviso_committente' => $avvisoRichiesto], $asset);
                $prescrizioni = array_key_exists('prescriptions', $data) ? $data['prescriptions'] : $assessment->prescriptions;
                $data['prescriptions'] = AvvisoCommittente::conPrescrizione($prescrizioni, $avviso['testo']);
            }

            // Una perizia validata e' un atto chiuso. Il messaggio dice anche
            // cosa fare al suo posto, perche' il tecnico che arriva qui ha un
            // errore da correggere e deve sapere come si fa
            // Non abort_if: il messaggio verrebbe costruito comunque e leggerebbe
            // una data che sulle perizie non validate non c'è
            if ($assessment->isValidated()) {
                abort(409, 'La perizia e\' stata validata il '
                    .$assessment->validated_at->timezone('Europe/Rome')->format('d/m/Y')
                    .' e non e\' piu\' modificabile. Per correggerla, registra una nuova '
                    .'perizia sullo stesso albero: la piu\' recente supera la precedente.');
            }

            if (isset($data['version']) && (int) $data['version'] !== $assessment->version) {
                abort(409, "Conflitto di versione: la valutazione è stata modificata da altri (versione attuale {$assessment->version}).");
            }
            unset($data['version']);

            if (isset($data['survey']['difetti'])) {
                $data['survey']['difetti'] = array_intersect_key(
                    $data['survey']['difetti'],
                    array_flip(TreeAssessment::BODY_PARTS),
                );
            }

            $eraEmessa = $assessment->report_number !== null;
            $assessment->fill($data);

            // Ricontrollo automatico dalla classe, come alla registrazione:
            // l'etichetta del campo lo promette anche in correzione, e una
            // classe peggiorata deve accorciare la scadenza
            // Solo se il campo è stato inviato vuoto: una correzione che non
            // lo nomina affatto non deve toccare una data messa a mano
            if (array_key_exists('next_check_due', $data) && empty($data['next_check_due'])) {
                $mesi = $assessment->failure_class !== null
                    ? ($this->recheckMonths($request)[$assessment->failure_class] ?? null)
                    : null;
                $assessment->next_check_due = $mesi !== null && $assessment->assessed_on
                    ? $assessment->assessed_on->copy()->addMonths($mesi)
                    : null;
            }

            // Il contenuto è cambiato? Va deciso PRIMA di toccare i campi di
            // servizio: se li scrivessimo ora, un salvataggio senza modifiche
            // fatto da un altro utente risulterebbe comunque una modifica e
            // butterebbe via il numero di perizia già consegnato
            $contenutoCambiato = $assessment->isDirty();
            $assessment->updated_by = $request->user()->id;

            if ($assessment->next_check_due && $assessment->assessed_on
                && $assessment->next_check_due->lte($assessment->assessed_on)) {
                throw ValidationException::withMessages([
                    'next_check_due' => 'Il prossimo controllo deve essere successivo alla data del sopralluogo ('
                        .$assessment->assessed_on->format('d/m/Y').').',
                ]);
            }

            if ($eraEmessa && $contenutoCambiato) {
                $assessment->report_number = null;
                $assessment->report_issued_at = null;
            }
            if ($contenutoCambiato) {
                $assessment->version = $assessment->version + 1;
            }
            $assessment->save();

            Audit::log('vta.updated', $assessment, [
                'failure_class' => $assessment->failure_class,
                'protocollo_azzerato' => $eraEmessa && $assessment->report_number === null,
            ]);

            return $assessment;
        });

        if ($avviso !== null) {
            app(AvvisoCommittente::class)->invia($assessment, Asset::query()->findOrFail($assessment->tree_id), $request->user(), $avviso['testo'], $avviso['area_id']);
        }

        return response()->json(['data' => $assessment->load('assessor:id,name', 'avviso.area:id,name')]);
    }

    /**
     * Validazione: la perizia diventa un atto e il contenuto tecnico si
     * blocca. Il blocco vero e' nel database, qui si controlla che ci sia
     * tutto e si assegna il protocollo.
     */
    public function valida(Request $request, string $id): JsonResponse
    {
        $assessment = TreeAssessment::findOrFail($id);

        return response()->json([
            'data' => \App\Services\Trees\PeriziaValidation::valida($assessment, $request->user()),
        ]);
    }

    public function destroy(string $id): Response
    {
        $assessment = TreeAssessment::findOrFail($id);

        abort_if($assessment->isValidated(), 409,
            'La perizia e\' stata validata e non e\' piu\' cancellabile: '
            .'un atto emesso si supera con uno successivo, non si toglie.');

        $assessment->delete();

        Audit::log('vta.deleted', $assessment);

        return response()->noContent();
    }

    /**
     * Regole della scheda VTA. In creazione tipo e data sono obbligatori;
     * in correzione si tocca solo quello che si invia.
     *
     * @return array<string, array<int, mixed>>
     */
    /**
     * L'avviso al committente chiesto insieme alla valutazione: toglie il blocco
     * dai dati della valutazione, mette il testo fra le prescrizioni e lo
     * restituisce pronto per l'invio (null se non richiesto).
     *
     * @return array{testo: string, area_id: ?string}|null
     */
    private function avvisoRichiesto(array &$data, Asset $asset): ?array
    {
        $richiesta = $data['avviso_committente'] ?? null;
        unset($data['avviso_committente']);
        if (! ($richiesta['attivo'] ?? false)) {
            return null;
        }
        $areaId = $richiesta['area_id'] ?? null;
        $area = $areaId ? \App\Models\Area::query()->find($areaId) : null;
        $testo = trim((string) ($richiesta['testo'] ?? '')) !== ''
            ? trim((string) $richiesta['testo'])
            : AvvisoCommittente::testoProposto($asset, $area);
        $data['prescriptions'] = AvvisoCommittente::conPrescrizione($data['prescriptions'] ?? null, $testo);

        return ['testo' => $testo, 'area_id' => $area?->id];
    }

    /** Il tecnico segna che l'avviso e' rientrato: intervento fatto, area riaperta. */
    public function rientro(Request $request, string $id): JsonResponse
    {
        $dati = $request->validate(['nota' => ['nullable', 'string', 'max:500']]);
        $avviso = ClientAlert::query()->findOrFail($id);
        if ($avviso->resolved_at === null) {
            $avviso->forceFill([
                'resolved_at' => now(),
                'resolved_by' => $request->user()->id,
                'resolved_note' => $dati['nota'] ?? null,
            ])->save();
            Audit::log('avviso.committente_rientrato', $avviso, ['asset_id' => $avviso->asset_id, 'nota' => $dati['nota'] ?? null]);
        }

        return response()->json(['data' => $avviso->fresh(['area:id,name', 'acknowledger:id,name', 'resolver:id,name'])]);
    }

    private function rules(bool $perTutti = true): array
    {
        $obbligatorio = $perTutti ? 'required' : 'sometimes';

        return [
            'assessment_type' => [$obbligatorio, 'in:vta_visual,vta_instrumental,vsa,pull_test,aerial_inspection,other'],
            // Giornata italiana, non quella del server (UTC): dopo mezzanotte
            // la data proposta a video sarebbe "nel futuro" per il server
            'assessed_on' => [$obbligatorio, 'date', 'before_or_equal:'.now('Europe/Rome')->toDateString()],
            'assessor_external' => ['nullable', 'string', 'max:254'],
            // Copia dei dati del rilevatore scelto dall'elenco dell'organizzazione
            // (RilevatoriController): la perizia stampa quelli di quel giorno
            'assessor_details' => ['nullable', 'array'],
            'assessor_details.id' => ['nullable', 'string', 'max:40'],
            'assessor_details.nome' => ['nullable', 'string', 'max:150'],
            'assessor_details.titolo' => ['nullable', 'string', 'max:150'],
            'assessor_details.iscrizione' => ['nullable', 'string', 'max:200'],
            'assessor_details.partita_iva' => ['nullable', 'string', 'max:30'],
            'defects' => ['sometimes', 'array'],
            'targets' => ['sometimes', 'array'],
            'targets.*' => ['nullable', 'string', 'max:254'],
            'failure_class' => ['nullable', Rule::in(TreeAssessment::FAILURE_CLASSES)],
            'outcome' => ['nullable', 'in:ok,monitor,prescriptions,fell'],
            'prescriptions' => ['nullable', 'string'],
            // L'avviso al committente (area da chiudere): testo che entra fra le prescrizioni e parte via email
            'avviso_committente' => ['nullable', 'array'],
            'avviso_committente.attivo' => ['sometimes', 'boolean'],
            'avviso_committente.testo' => ['nullable', 'string', 'max:1000'],
            'avviso_committente.area_id' => ['nullable', 'uuid'],
            // Entro quando fare quello che si prescrive: diventa la data dell'ordine (GeneratorePrescrizioniVta)
            'prescriptions_due_on' => ['nullable', 'date'],
            // Pubblicazione della relazione come atto sul portale
            'is_public' => ['sometimes', 'boolean'],
            'next_check_due' => array_values(array_filter(
                ['nullable', 'date', $perTutti ? 'after:assessed_on' : null],
            )),
            // Scheda estesa della perizia: tutte voci descrittive facoltative
            'survey' => ['sometimes', 'array'],
            'survey.contesto' => ['nullable', 'array'],
            'survey.contesto.ambito' => ['nullable', 'string', 'max:150'],
            'survey.contesto.sito_radicazione' => ['nullable', 'string', 'max:150'],
            'survey.contesto.disposizione' => ['nullable', 'string', 'max:150'],
            'survey.contesto.accessibilita' => ['nullable', 'string', 'max:150'],
            'survey.interferenze' => ['nullable', 'string', 'max:1000'],
            'survey.giudizio' => ['nullable', 'array'],
            'survey.giudizio.fase_fisiologica' => ['nullable', 'string', 'max:150'],
            'survey.giudizio.stato_vegetativo' => ['nullable', 'string', 'max:150'],
            'survey.giudizio.sintetico' => ['nullable', 'string', 'max:150'],
            'survey.giudizio.patologie_quarantena' => ['nullable', 'string', 'max:500'],
            'survey.difetti' => ['nullable', 'array'],
            'survey.difetti.*' => ['nullable', 'string', 'max:2000'],
            'survey.integrazione_vta' => ['nullable', 'string', 'max:1000'],
            'survey.priorita_intervento' => ['nullable', 'string', 'max:100'],
            'survey.conclusioni' => ['nullable', 'string', 'max:4000'],
        ];
    }

    /** @return array<string, string> messaggi in italiano per chi compila la scheda */
    private function messages(): array
    {
        return [
            'assessed_on.before_or_equal' => 'La data del sopralluogo non può essere nel futuro.',
            'assessed_on.required' => 'Indica la data del sopralluogo.',
            'assessment_type.required' => 'Indica il tipo di valutazione.',
        ];
    }

    /** @return array<string, int|null> */
    private function recheckMonths(Request $request): array
    {
        $settings = \App\Models\Organization::find($request->user()->tenant_id)?->settings ?? [];

        return array_replace(
            TreeAssessment::DEFAULT_RECHECK_MONTHS,
            $settings['vta_recheck_months'] ?? [],
        );
    }
}
