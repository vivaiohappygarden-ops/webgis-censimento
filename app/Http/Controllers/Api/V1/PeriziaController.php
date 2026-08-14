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

    /** Dati del professionista che firma: intestazione e firma del documento. */
    public function settings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->professionista($request->user()->tenant_id)]);
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
        $settings['professionista'] = array_map(
            fn ($v) => $v !== null && trim($v) !== '' ? trim($v) : null,
            $data,
        );
        $organization->forceFill(['settings' => $settings])->save();

        Audit::log('perizia.settings_updated', null, ['nome' => $settings['professionista']['nome']]);

        return response()->json(['data' => $this->professionista($organization->id)]);
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

        $survey = $assessment->survey ?? [];
        $point = DB::selectOne(
            'SELECT ST_Y(ST_Centroid(geom)) AS lat, ST_X(ST_Centroid(geom)) AS lon FROM assets WHERE id = ?',
            [$asset->id],
        );

        $pdf = $renderer->render('pdf.perizia', [
            'riferimento' => $this->reference($assessment, $asset),
            'emessaIl' => now('Europe/Rome'),
            'professionista' => $this->professionista($assessment->tenant_id),
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
            'giudizio' => $survey['giudizio'] ?? [],
            'difetti' => $this->defectsByPart($assessment),
            'analisi' => $this->analyses($assessment),
            'classiDescrizione' => self::CLASS_DESCRIPTIONS,
            'integrazioneVta' => $survey['integrazione_vta'] ?? null,
            'prioritaIntervento' => $survey['priorita_intervento'] ?? null,
            'fotoDataUri' => $this->photos($asset->id),
            'mappaDataUri' => $point?->lat !== null
                ? $map->pngDataUri((float) $point->lat, (float) $point->lon)
                : null,
            // Conclusioni scritte a mano, o testo composto dalla classe
            'conclusioni' => trim((string) ($survey['conclusioni'] ?? '')) !== ''
                ? $survey['conclusioni']
                : $this->defaultConclusions($assessment),
        ]);

        Audit::log('perizia.pdf', $assessment, ['asset_id' => $asset->id]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="perizia_'.($asset->census_code ?: 'albero').'.pdf"',
        ]);
    }

    /** @return array<string, string|null> */
    private function professionista(string $tenantId): array
    {
        $organization = Organization::query()->find($tenantId);
        $saved = $organization?->settings['professionista'] ?? [];

        return [
            // Senza dati del professionista resta il nome dell'organizzazione:
            // il documento esce comunque, con l'intestazione da completare
            'nome' => $saved['nome'] ?? $organization?->name ?? '',
            'titolo' => $saved['titolo'] ?? 'Tecnico incaricato',
            'iscrizione' => $saved['iscrizione'] ?? null,
            'recapiti' => $saved['recapiti'] ?? null,
        ];
    }

    /** Riferimento leggibile della perizia: codice elemento e data del rilievo. */
    private function reference(TreeAssessment $assessment, \App\Models\Asset $asset): string
    {
        return sprintf(
            '%s/%s',
            $asset->census_code ?: substr($asset->id, 0, 8),
            $assessment->assessed_on?->format('Y-m-d') ?? '',
        );
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

    /** Fino a 4 foto dell'elemento, incorporate nel PDF. */
    private function photos(string $assetId): array
    {
        $disk = Storage::disk();

        return Photo::query()
            ->where('asset_id', $assetId)
            ->orderBy('created_at')
            ->limit(4)->get()
            ->filter(fn (Photo $p) => $disk->exists($p->s3_key))
            ->map(fn (Photo $p) => 'data:'.($p->mime_type ?: 'image/jpeg').';base64,'
                .base64_encode($disk->get($p->s3_key)))
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

        if ($assessment->prescriptions) {
            $testo .= ' Si prescrivono gli interventi indicati al punto 8.';
        }
        if ($assessment->next_check_due) {
            $testo .= ' Il prossimo controllo è previsto entro il '
                .$assessment->next_check_due->format('d/m/Y').'.';
        }

        return $testo;
    }
}
