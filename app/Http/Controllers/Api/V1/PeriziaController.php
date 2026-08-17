<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InstrumentalAnalysis;
use App\Models\Organization;
use App\Models\Photo;
use App\Models\TreeAssessment;
use App\Services\Maps\StaticMap;
use App\Services\Pdf\PdfRenderer;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Perizia di valutazione della stabilità (metodo VTA): il documento che
 * il tecnico firma e consegna al committente. Raccoglie la scheda di
 * valutazione, i dati dendrometrici, le analisi strumentali, le foto e
 * l'inquadramento cartografico in un unico PDF.
 */
class PeriziaController extends Controller implements HasMiddleware
{
    private const CLASS_DESCRIPTIONS = [
        'A' => 'propensione al cedimento trascurabile',
        'B' => 'propensione al cedimento bassa',
        'C' => 'propensione al cedimento moderata',
        'C/D' => 'propensione al cedimento elevata',
        'D' => 'propensione al cedimento estrema',
    ];

    private const PART_LABELS = [
        'rilevamenti' => 'Rilevamenti',
        'radici' => 'Radici',
        'colletto' => 'Colletto',
        'fusto' => 'Fusto',
        'castello' => 'Castello',
        'branche' => 'Branche',
        'chioma' => 'Chioma e foglie',
    ];

    private const TYPE_LABELS = [
        'vta_visual' => 'Analisi visiva (VTA)',
        'vta_instrumental' => 'Analisi visiva con indagini strumentali (VTA)',
        'vsa' => 'Valutazione visiva speditiva (VSA)',
        'pull_test' => 'Prova di trazione controllata',
        'aerial_inspection' => 'Ispezione in quota',
        'other' => 'Altra valutazione',
    ];

    private const OUTCOME_LABELS = [
        'ok' => 'Esemplare idoneo: nessun intervento necessario',
        'monitor' => 'Esemplare da monitorare',
        'prescriptions' => 'Esemplare da sottoporre agli interventi prescritti',
        'fell' => 'Se ne propone l\'abbattimento',
    ];

    private const INSTRUMENT_LABELS = [
        'resistograph' => 'Resistografo',
        'sonic_tomograph' => 'Tomografo sonico',
        'electric_tomograph' => 'Tomografo elettrico',
        'pull_test' => 'Prova di trazione',
        'dendro_densimeter' => 'Dendrodensimetro',
        'other' => 'Altro strumento',
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('can:assets.view', only: ['pdf']),
            new Middleware('can:users.manage', only: ['settings', 'updateSettings']),
        ];
    }

    /**
     * Dati del professionista che firma. Qui si restituisce quello che è
     * stato davvero salvato (anche vuoto): mostrare i ripieghi come se
     * fossero compilati farebbe credere di aver già configurato l'intestazione.
     */
    public function settings(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->professionista($request->user()->tenant_id, withDefaults: false),
            'defaults' => $this->professionista($request->user()->tenant_id),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nome' => ['nullable', 'string', 'max:150'],
            'titolo' => ['nullable', 'string', 'max:150'],
            'iscrizione' => ['nullable', 'string', 'max:200'],
            'recapiti' => ['nullable', 'string', 'max:300'],
        ]);

        $organization = Organization::query()->findOrFail($request->user()->tenant_id);
        $settings = $organization->settings ?? [];
        // Salvataggio parziale: i campi non inviati restano quelli di prima,
        // non vengono azzerati
        $settings['professionista'] = array_replace(
            $settings['professionista'] ?? [],
            array_map(
                fn ($v) => $v !== null && trim($v) !== '' ? trim($v) : null,
                $data,
            ),
        );
        $organization->forceFill(['settings' => $settings])->save();

        Audit::log('perizia.settings_updated', null, [
            'nome' => $settings['professionista']['nome'] ?? null,
        ]);

        return response()->json(['data' => $this->professionista($organization->id, withDefaults: false)]);
    }

    /** La perizia in PDF per una valutazione. */
    public function pdf(Request $request, string $id, PdfRenderer $renderer, StaticMap $map)
    {
        $assessment = TreeAssessment::query()
            ->with(['tree', 'assessor:id,name'])
            ->findOrFail($id);

        $asset = \App\Models\Asset::query()
            ->with(['area.locality.site.client'])
            ->findOrFail($assessment->tree_id);
        $tree = $assessment->tree;
        abort_if($tree === null, 404, 'Valutazione senza scheda albero.');

        // Senza classe di propensione al cedimento non c'è perizia: è la
        // conclusione tecnica che il professionista firma
        if (! in_array($assessment->failure_class, TreeAssessment::FAILURE_CLASSES, true)) {
            throw ValidationException::withMessages([
                'failure_class' => 'La perizia non si può emettere senza la classe di propensione al cedimento: '
                    .'apri la valutazione, indica la classe (A, B, C, C/D o D) e riprova.',
            ]);
        }

        $this->assignReportNumber($assessment);

        $survey = $assessment->survey ?? [];
        $point = DB::selectOne(
            'SELECT ST_Y(ST_Centroid(geom)) AS lat, ST_X(ST_Centroid(geom)) AS lon FROM assets WHERE id = ?',
            [$asset->id],
        );

        $pdf = $renderer->render('pdf.perizia', [
            'riferimento' => $assessment->report_number,
            'emessaIl' => $assessment->report_issued_at->setTimezone('Europe/Rome'),
            'professionista' => $this->professionista($assessment->tenant_id),
            'rilevatore' => $assessment->assessor_external ?: $assessment->assessor?->name,
            'tipoValutazione' => self::TYPE_LABELS[$assessment->assessment_type] ?? $assessment->assessment_type,
            'esito' => self::OUTCOME_LABELS[$assessment->outcome] ?? null,
            'asset' => $asset,
            'tree' => $tree,
            'assessment' => $assessment,
            'committente' => $asset->area?->locality?->site?->client?->name,
            'coordinate' => $point?->lat !== null
                ? sprintf('%.6f, %.6f', $point->lat, $point->lon)
                : 'non disponibili',
            'contesto' => $survey['contesto'] ?? [],
            'bersagli' => $this->joinList($assessment->targets),
            'interferenze' => $survey['interferenze'] ?? null,
            // Lo stato vegetativo si può indicare nella scheda albero o nella
            // scheda della perizia: nel documento ne compare uno solo, quello
            // scritto per la perizia, con la scheda albero come ripiego
            'giudizio' => [
                ...($survey['giudizio'] ?? []),
                'stato_vegetativo' => trim((string) ($survey['giudizio']['stato_vegetativo'] ?? '')) !== ''
                    ? $survey['giudizio']['stato_vegetativo']
                    : $tree->vegetative_state,
            ],
            'difetti' => $this->defectsByPart($assessment),
            'analisi' => $this->analyses($assessment),
            'classiDescrizione' => self::CLASS_DESCRIPTIONS,
            'integrazioneVta' => $survey['integrazione_vta'] ?? null,
            'prioritaIntervento' => $survey['priorita_intervento'] ?? null,
            'foto' => $this->photos($asset->id, $assessment),
            'mappaDataUri' => $point?->lat !== null
                ? $map->pngDataUri((float) $point->lat, (float) $point->lon)
                : null,
            // Conclusioni scritte a mano, o testo composto dalla classe
            'conclusioni' => trim((string) ($survey['conclusioni'] ?? '')) !== ''
                ? $survey['conclusioni']
                : $this->defaultConclusions($assessment),
        ]);

        Audit::log('perizia.pdf', $assessment, [
            'asset_id' => $asset->id,
            'report_number' => $assessment->report_number,
        ]);

        // Il codice censimento è testo libero: senza ripulirlo finirebbe
        // tale e quale nell'intestazione del download
        $name = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($assessment->report_number ?: 'perizia'));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$name}.pdf\"",
        ]);
    }

    /**
     * Numero di protocollo e data di emissione: si assegnano alla prima
     * stampa e restano quelli. Ristampare la stessa perizia deve dare lo
     * stesso documento, non un documento nuovo con un'altra data.
     */
    private function assignReportNumber(TreeAssessment $assessment): void
    {
        if ($assessment->report_number !== null && $assessment->report_issued_at !== null) {
            return;
        }

        DB::transaction(function () use ($assessment) {
            $fresh = TreeAssessment::query()->lockForUpdate()->findOrFail($assessment->id);
            if ($fresh->report_number === null || $fresh->report_issued_at === null) {
                $fresh->forceFill([
                    'report_number' => $fresh->report_number ?: TreeAssessment::nextReportNumber($fresh->tenant_id),
                    'report_issued_at' => $fresh->report_issued_at ?: now(),
                ])->save();
            }
            $assessment->report_number = $fresh->report_number;
            $assessment->report_issued_at = $fresh->report_issued_at;
        });
    }

    /** @return array<string, string|null> */
    private function professionista(string $tenantId, bool $withDefaults = true): array
    {
        $organization = Organization::query()->find($tenantId);
        $saved = $organization?->settings['professionista'] ?? [];

        if (! $withDefaults) {
            return [
                'nome' => $saved['nome'] ?? null,
                'titolo' => $saved['titolo'] ?? null,
                'iscrizione' => $saved['iscrizione'] ?? null,
                'recapiti' => $saved['recapiti'] ?? null,
            ];
        }

        return [
            // Senza dati del professionista resta il nome dell'organizzazione:
            // il documento esce comunque, con l'intestazione da completare
            'nome' => ($saved['nome'] ?? null) ?: ($organization?->name ?? ''),
            'titolo' => ($saved['titolo'] ?? null) ?: 'Tecnico incaricato',
            'iscrizione' => $saved['iscrizione'] ?? null,
            'recapiti' => $saved['recapiti'] ?? null,
        ];
    }

    /** @return array<string, string> difetti per parte, con le etichette della scheda */
    private function defectsByPart(TreeAssessment $assessment): array
    {
        $parts = $assessment->survey['difetti'] ?? [];
        $out = [];
        foreach (self::PART_LABELS as $key => $label) {
            $value = trim((string) ($parts[$key] ?? ''));
            if ($value !== '') {
                $out[$label] = $value;
            }
        }

        // Compatibilità con le valutazioni registrate prima della scheda
        // estesa: l'elenco libero dei difetti finisce tra i rilevamenti
        if ($out === [] && ! empty($assessment->defects)) {
            $out['Rilevamenti'] = $this->joinList($assessment->defects);
        }

        return $out;
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function analyses(TreeAssessment $assessment)
    {
        return InstrumentalAnalysis::query()
            ->where('assessment_id', $assessment->id)
            ->orderBy('measured_at')
            ->get()
            ->map(function (InstrumentalAnalysis $a) {
                // Le misure sono coppie voce-valore: in stampa diventano
                // una riga di intestazioni e una di valori
                $measures = collect($a->measures ?? [])
                    ->filter(fn ($v) => $v !== null && $v !== '')
                    ->all();

                return (object) [
                    'label' => self::INSTRUMENT_LABELS[$a->instrument_type] ?? $a->instrument_type,
                    'strumento' => $a->instrument_model,
                    'measured_at' => $a->measured_at,
                    'altezza' => $a->measurement_height_cm,
                    'rows' => $measures === [] ? [] : [$measures],
                    'summary_text' => $a->notes,
                ];
            });
    }

    /**
     * Fino a 4 foto per la documentazione fotografica: le più recenti che
     * non siano successive al sopralluogo (una perizia non documenta con
     * foto scattate dopo), ciascuna con la sua data.
     *
     * Le immagini sono SEMPRE ricodificate e ridotte: la ricodifica elimina
     * EXIF e coordinate GPS del telefono, che altrimenti resterebbero
     * leggibili dentro il PDF firmato, e tiene il documento leggero.
     *
     * @return list<array{data: string, scattata: ?string, categoria: ?string}>
     */
    private function photos(string $assetId, TreeAssessment $assessment): array
    {
        $disk = Storage::disk();
        $limite = $assessment->assessed_on?->copy()->endOfDay();

        $query = fn (bool $entroSopralluogo) => Photo::query()
            ->where('asset_id', $assetId)
            ->when($entroSopralluogo && $limite !== null, fn ($q) => $q->whereRaw(
                'COALESCE(taken_at, created_at) <= ?', [$limite],
            ))
            ->orderByRaw('COALESCE(taken_at, created_at) DESC')
            ->limit(4)
            ->get();

        $photos = $query(true);
        if ($photos->isEmpty()) {
            // Nessuna foto entro il sopralluogo: si usano comunque quelle
            // disponibili, ma la data stampata sotto lo dice chiaramente
            $photos = $query(false);
        }

        return $photos
            ->filter(fn (Photo $p) => $disk->exists($p->s3_key))
            ->map(function (Photo $p) use ($disk) {
                $jpeg = \App\Services\Photos\ImageDerivative::jpeg(
                    $disk->get($p->s3_key), maxDimension: 1200, quality: 78,
                );
                if ($jpeg === null) {
                    return null;
                }

                return [
                    'data' => 'data:image/jpeg;base64,'.base64_encode($jpeg),
                    'scattata' => ($p->taken_at ?? $p->created_at)?->setTimezone('Europe/Rome')->format('d/m/Y'),
                    'categoria' => $p->category,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function joinList(?array $values): string
    {
        if (empty($values)) {
            return '';
        }

        return collect($values)
            ->map(fn ($v) => is_array($v) ? implode(' ', array_filter($v, 'is_scalar')) : (string) $v)
            ->filter(fn ($v) => trim($v) !== '')
            ->implode('; ');
    }

    /** Conclusioni di partenza quando il tecnico non le scrive a mano. */
    private function defaultConclusions(TreeAssessment $assessment): string
    {
        $class = $assessment->failure_class;
        $descrizione = self::CLASS_DESCRIPTIONS[$class] ?? 'propensione al cedimento non determinata';
        $testo = "Sulla base dell'analisi visiva condotta con metodo VTA"
            .($assessment->instrumentalAnalyses()->exists() ? ', integrata da indagini strumentali,' : '')
            ." l'esemplare è ascritto alla classe {$class} ({$descrizione}).";

        if (isset(self::OUTCOME_LABELS[$assessment->outcome])) {
            $testo .= ' '.self::OUTCOME_LABELS[$assessment->outcome].'.';
        }
        if ($assessment->prescriptions) {
            $testo .= ' Si prescrivono gli interventi indicati nel capitolo delle prescrizioni.';
        }
        if ($assessment->next_check_due) {
            $testo .= ' Il prossimo controllo è previsto entro il '
                .$assessment->next_check_due->format('d/m/Y').'.';
        }

        return $testo;
    }
}
