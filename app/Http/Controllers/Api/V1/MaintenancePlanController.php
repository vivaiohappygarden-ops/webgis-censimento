<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\MaintenancePlan;
use App\Models\WorkType;
use App\Services\Works\GeneratorePiani;
use App\Services\Works\ImpresaDelCommittente;
use App\Support\Audit;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

/**
 * Piani di manutenzione pluriennali: la dichiarazione ("potatura ogni 3
 * anni su quest'area") sta qui; gli ordini del periodo li genera
 * GeneratorePiani, con anteprima e conferma sullo stesso metodo.
 */
class MaintenancePlanController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:works.view', only: ['index']),
            new Middleware('can:works.manage', only: ['store', 'update', 'destroy', 'genera']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        ListQuery::validateUuidFilters($request, ['client_id', 'area_id']);

        $query = MaintenancePlan::query()
            ->with([
                'area:id,name,code,status,locality_id',
                'area.locality:id,name,site_id',
                'area.locality.site:id,name,client_id',
                'area.locality.site.client:id,name',
                'workType:id,code,name',
                'team:id,name,is_external,client_id',
            ]);

        if ($request->filled('area_id')) {
            $query->where('area_id', $request->string('area_id'));
        }
        if ($request->filled('client_id')) {
            // Il committente non sta sul piano: si risale la catena dell'area
            $query->whereHas('area.locality.site',
                fn ($w) => $w->where('client_id', $request->string('client_id')));
        }

        return response()->json(
            $query->orderBy('created_at')->orderBy('id')
                ->paginate(ListQuery::perPage($request, 50, 200))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $user = $request->user();

        $piano = MaintenancePlan::create([
            ...$data,
            'tenant_id' => $user->tenant_id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Audit::log('maintenance_plan.created', $piano);

        return response()->json(['data' => $this->carica($piano->id)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $piano = MaintenancePlan::query()->findOrFail($id);
        $data = $this->validated($request, required: false, corrente: $piano);

        $piano->fill($data);
        $piano->updated_by = $request->user()->id;
        $piano->save();

        Audit::log('maintenance_plan.updated', $piano);

        return response()->json(['data' => $this->carica($piano->id)]);
    }

    public function destroy(string $id): Response
    {
        $piano = MaintenancePlan::query()->findOrFail($id);
        $piano->delete();

        Audit::log('maintenance_plan.deleted', $piano);

        return response()->noContent();
    }

    /**
     * Genera (o conta, con prova=1) gli ordini dovuti dai piani nel periodo.
     * Anteprima ed esecuzione passano dallo stesso metodo del servizio:
     * quello che l'anteprima elenca e' quello che la conferma crea.
     */
    public function genera(Request $request): JsonResponse
    {
        $data = $request->validate([
            'da' => ['required', 'date_format:Y-m'],
            'a' => ['required', 'date_format:Y-m', 'after_or_equal:da'],
            'prova' => ['sometimes', 'boolean'],
        ]);

        // Tetto di 12 mesi: un periodo piu' lungo produrrebbe centinaia di
        // ordini in un colpo e sarebbe comunque tutto da correggere
        [$annoDa, $meseDa] = explode('-', $data['da']);
        [$annoA, $meseA] = explode('-', $data['a']);
        $mesi = ((int) $annoA - (int) $annoDa) * 12 + ((int) $meseA - (int) $meseDa) + 1;
        if ($mesi > 12) {
            throw ValidationException::withMessages([
                'a' => 'Il periodo può coprire al massimo 12 mesi.',
            ]);
        }

        return response()->json([
            'data' => app(GeneratorePiani::class)->genera(
                $data['da'], $data['a'], $request->user(), $request->boolean('prova'),
            ),
        ]);
    }

    private function carica(string $id): MaintenancePlan
    {
        return MaintenancePlan::query()
            ->with([
                'area:id,name,code,status,locality_id',
                'area.locality:id,name,site_id',
                'area.locality.site:id,name,client_id',
                'area.locality.site.client:id,name',
                'workType:id,code,name',
                'team:id,name,is_external,client_id',
            ])
            ->findOrFail($id);
    }

    private function validated(Request $request, bool $required = true, ?MaintenancePlan $corrente = null): array
    {
        $presence = $required ? 'required' : 'sometimes';

        $data = $request->validate([
            'area_id' => [$presence, 'uuid'],
            'work_type_id' => [$presence, 'uuid'],
            'interval_months' => [$presence, 'integer', 'min:1', 'max:120'],
            // La finestra o c'e' tutta o non c'e' (vincolo anche sul DB);
            // month_from > month_to e' ammesso: finestra a cavallo del
            // capodanno (novembre-febbraio per le potature invernali)
            'month_from' => ['nullable', 'required_with:month_to', 'integer', 'min:1', 'max:12'],
            'month_to' => ['nullable', 'required_with:month_from', 'integer', 'min:1', 'max:12'],
            'team_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // "O tutta o niente" vale anche per una modifica parziale: i
        // required_with guardano solo la richiesta, quindi un PATCH che
        // tocca un estremo solo (month_from senza month_to, o un estremo
        // a null) passerebbe la validazione e morirebbe sul CHECK del DB
        // con un errore grezzo. Qui si guardano i valori FINALI.
        $finestraDa = array_key_exists('month_from', $data) ? $data['month_from'] : $corrente?->month_from;
        $finestraA = array_key_exists('month_to', $data) ? $data['month_to'] : $corrente?->month_to;
        if (($finestraDa === null) !== ($finestraA === null)) {
            throw ValidationException::withMessages([
                'month_to' => 'La finestra stagionale si indica con tutti e due i mesi, o con nessuno.',
            ]);
        }

        // I riferimenti devono appartenere al tenant (404 se estranei)
        $areaId = $data['area_id'] ?? $corrente?->area_id;
        if (array_key_exists('area_id', $data)) {
            Area::query()->findOrFail($data['area_id']);
        }
        if (array_key_exists('work_type_id', $data)) {
            WorkType::query()->findOrFail($data['work_type_id']);
        }

        // La squadra di preferenza segue la regola del blocco 12: un'impresa
        // esterna si dichiara solo su piani del SUO committente. Il controllo
        // scatta quando la richiesta tocca squadra o area (con i valori
        // finali): una modifica che non le riguarda, come spegnere il piano,
        // deve passare anche se nel frattempo l'area e' stata eliminata
        $toccaSquadraOArea = array_key_exists('team_id', $data) || array_key_exists('area_id', $data);
        $teamId = array_key_exists('team_id', $data) ? $data['team_id'] : $corrente?->team_id;
        if ($teamId !== null && $toccaSquadraOArea) {
            \App\Models\Team::query()->findOrFail($teamId);
            $area = Area::query()->with('locality.site:id,client_id')->findOrFail($areaId);
            ImpresaDelCommittente::verifica($teamId, $area->locality?->site?->client_id);
        }

        // "Un piano per area e lavorazione" lo garantisce l'indice unico sul
        // DB; qui si traduce l'errore in italiano prima che diventi un 500
        $areaFinale = $areaId;
        $tipoFinale = $data['work_type_id'] ?? $corrente?->work_type_id;
        $doppione = MaintenancePlan::query()
            ->where('area_id', $areaFinale)
            ->where('work_type_id', $tipoFinale)
            ->when($corrente !== null, fn ($q) => $q->whereKeyNot($corrente->id))
            ->exists();
        if ($doppione) {
            throw ValidationException::withMessages([
                'work_type_id' => 'Esiste già un piano per questa area e lavorazione: modifica quello.',
            ]);
        }

        return $data;
    }
}
