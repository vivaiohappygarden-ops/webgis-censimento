<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Services\Works\AzioniMultiple;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Azioni su piu' elementi selezionati in un elenco.
 *
 * Ogni risposta dice cosa e' stato fatto e cosa e' stato saltato con il
 * motivo: chi usa un'azione di gruppo deve poter controllare l'esito, non
 * fidarsi.
 */
class AzioniMultipleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:works.manage', only: ['chiudiLavori', 'collegaElementi']),
            new Middleware('can:assets.update', only: ['modificaElementi', 'modificaAlberi']),
        ];
    }

    public function chiudiLavori(Request $request): JsonResponse
    {
        $data = $request->validate($this->regoleIds());

        // prova=1: si conta cosa succederebbe senza eseguire, per mostrare
        // "N verranno chiusi, M esclusi" prima della conferma
        return response()->json([
            'data' => AzioniMultiple::chiudiLavori($data['ids'], $request->user(), $request->boolean('prova')),
        ]);
    }

    public function modificaElementi(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->regoleIds(),
            // Solo campi che hanno senso applicati in blocco.
            //
            // Fuori restano: la geometria, che e' il posto di quel singolo
            // elemento; e lo STATO, perche' l'abbattimento ha un suo flusso
            // che scrive anche data di rimozione, fine validita' e scheda
            // albero. Cambiarlo da qui sarebbe una porta di servizio per
            // scavalcarlo e lasciare i dati disallineati. Specie e misure
            // hanno il loro percorso, con le sue difese: modificaAlberi().
            'public_hidden' => ['sometimes', 'boolean'],
            'surveyed_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $modifiche = array_intersect_key($data, array_flip(['public_hidden', 'surveyed_at']));

        if ($modifiche === []) {
            return response()->json(['message' => 'Nessuna modifica indicata.'], 422);
        }

        return response()->json([
            'data' => AzioniMultiple::modificaElementi(
                $data['ids'], $modifiche, $request->user(), $request->boolean('prova'),
            ),
        ]);
    }

    /**
     * Specie e misure su piu' alberi in una volta.
     *
     * Si scrivono solo i campi indicati (una casella per campo, come nel
     * riquadro della pagina), e con solo_vuoti=1 si riempiono i buchi senza
     * toccare quello che c'e'. Con prova=1 si legge prima quante schede
     * cambierebbero davvero e quali restano fuori, con il motivo.
     */
    public function modificaAlberi(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->regoleIds(),
            'solo_vuoti' => ['sometimes', 'boolean'],
            'campi' => ['required', 'array', 'min:1'],
            // Solo le chiavi dell'elenco: il resto della scheda albero
            // (monumentale, dedicato, date di impianto e rimozione) non si
            // tocca in blocco, sono decisioni una per una
            'campi.*' => ['nullable'],
            'campi.genus' => ['sometimes', 'nullable', 'string', 'max:100'],
            'campi.species' => ['sometimes', 'nullable', 'string', 'max:150'],
            'campi.cultivar' => ['sometimes', 'nullable', 'string', 'max:150'],
            'campi.common_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'campi.height_m' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:150'],
            'campi.dbh_cm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:2000'],
            'campi.trunk_circumference_cm' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:6000'],
            'campi.crown_diameter_m' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'campi.crown_insertion_m' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'campi.trunk_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
            'campi.age_years_est' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3000'],
            'campi.age_qualifier' => ['sometimes', 'nullable', \Illuminate\Validation\Rule::in(config('agronomia.qualificatore_eta'))],
            'campi.age_class' => ['sometimes', 'nullable', \Illuminate\Validation\Rule::in(config('agronomia.fase_fisiologica'))],
            'campi.vegetative_state' => ['sometimes', 'nullable', \Illuminate\Validation\Rule::in(config('agronomia.stato_vegetativo'))],
            'campi.social_position' => ['sometimes', 'nullable', \Illuminate\Validation\Rule::in(config('agronomia.posizione_sociale'))],
            'campi.growth_site' => ['sometimes', 'nullable', \Illuminate\Validation\Rule::in(config('agronomia.sito_di_crescita'))],
            'campi.target' => ['sometimes', 'nullable', \Illuminate\Validation\Rule::in(config('agronomia.bersaglio'))],
        ]);

        $campi = array_intersect_key($data['campi'], AzioniMultiple::CAMPI_ALBERO);

        $sconosciuti = array_diff(array_keys($data['campi']), array_keys(AzioniMultiple::CAMPI_ALBERO));
        if ($sconosciuti !== []) {
            // Meglio un rifiuto chiaro che un campo ignorato in silenzio: chi
            // ha scritto quella chiave crede di averla cambiata
            return response()->json([
                'message' => 'Campi non modificabili in blocco: '.implode(', ', $sconosciuti).'.',
            ], 422);
        }
        if ($campi === []) {
            return response()->json(['message' => 'Nessun campo indicato.'], 422);
        }

        return response()->json([
            'data' => AzioniMultiple::modificaAlberi(
                $data['ids'], $campi, $request->boolean('solo_vuoti'),
                $request->user(), $request->boolean('prova'),
            ),
            'etichette' => AzioniMultiple::CAMPI_ALBERO,
        ]);
    }

    public function collegaElementi(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            ...$this->regoleIds(),
            'work_type_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $ordine = WorkOrder::query()->findOrFail($id);

        abort_if(in_array($ordine->status, ['completed', 'cancelled'], true), 409,
            'L\'ordine di lavoro e\' chiuso: non si possono piu\' aggiungere elementi.');

        if (! empty($data['work_type_id'])) {
            \App\Models\WorkType::query()->findOrFail($data['work_type_id']);
        }

        return response()->json([
            'data' => AzioniMultiple::collegaElementi(
                $ordine, $data['ids'], $data['work_type_id'] ?? null, $request->boolean('prova'),
            ),
        ]);
    }

    private function regoleIds(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.AzioniMultiple::MASSIMO],
            'ids.*' => ['uuid'],
        ];
    }
}
