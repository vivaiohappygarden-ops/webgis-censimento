<?php

namespace App\Support;

use App\Services\Oggi\CoseDaFare;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * I filtri dell'elenco degli elementi censiti, scritti una volta sola: li
 * usano l'elenco (AssetController::index), il suo riepilogo e le esportazioni
 * CSV ed Excel, che devono esportare esattamente quello che si vede. Prima
 * l'esportazione teneva una copia del blocco dell'elenco: al primo filtro
 * aggiunto (dal 26/09/2026: ricontrollo VTA e alberi senza specie) le due
 * pagine avrebbero mostrato insiemi diversi.
 */
final class FiltriElementi
{
    /** I parametri che contano, per il registro delle esportazioni. */
    public const PARAMETRI = [
        'area_id', 'locality_id', 'client_id', 'object_type_id', 'type_code', 'status',
        'hide_removed', 'archivio', 'q', 'vta', 'senza_specie',
    ];

    /** Stati del ricontrollo VTA ammessi dal filtro `vta`. */
    public const VTA = ['scaduta', 'in_scadenza', 'mai', 'valutato'];

    public static function applica(Request $request, Builder $query): Builder
    {
        ListQuery::validateUuidFilters($request, ['area_id', 'object_type_id', 'client_id', 'locality_id']);
        $request->validate([
            'vta' => ['sometimes', 'nullable', 'in:'.implode(',', self::VTA)],
            'senza_specie' => ['sometimes', 'nullable', 'boolean'],
        ]);

        if ($request->filled('area_id')) {
            $query->where('assets.area_id', $request->string('area_id'));
        }
        if ($request->filled('locality_id')) {
            $query->whereHas('area', fn ($w) => $w->where('locality_id', $request->string('locality_id')));
        }
        // Committente: assets -> aree -> località -> sedi -> cliente
        if ($request->filled('client_id')) {
            $query->whereHas('area.locality.site', fn ($w) => $w->where('client_id', $request->string('client_id')));
        }
        if ($request->filled('object_type_id')) {
            $query->where('assets.object_type_id', $request->string('object_type_id'));
        }
        if ($request->filled('type_code')) {
            $query->whereHas('objectType', fn ($w) => $w->where('code', $request->string('type_code')));
        }
        if ($request->filled('status')) {
            $query->where('assets.status', $request->string('status'));
        }
        // L'archivio (schede abbattute/rimosse e dismesse) sta fuori dal
        // lavoro di tutti i giorni: archivio=0 lo nasconde, archivio=1 mostra
        // SOLO quello (la vista "Archivio"). Il filtro di stato esplicito
        // vince: chi chiede status=dismissed vuole vederli anche con la spunta
        // dell'archivio spenta. Senza alcun parametro si vede tutto, così le
        // altre pagine che usano questa API non cambiano; hide_removed resta
        // con il vecchio significato (solo abbattuti) per le viste salvate e i
        // client esistenti.
        if ($request->has('archivio')) {
            if ($request->boolean('archivio')) {
                $query->inArchivio();
            } elseif (! $request->filled('status')) {
                $query->fuoriArchivio();
            }
        } elseif ($request->boolean('hide_removed')) {
            $query->where('assets.status', '!=', 'removed');
        }
        if ($request->filled('q')) {
            $request->validate(['q' => RicercaTestuale::regole()]);
            $query->cercaTesto($request->string('q'));
        }
        if ($request->filled('vta')) {
            self::conVta($query, $request->string('vta')->toString());
        }
        if ($request->boolean('senza_specie')) {
            self::senzaSpecie($query);
        }

        return $query;
    }

    /**
     * Alberi per stato del ricontrollo VTA, con la definizione dello
     * scadenzario: conta l'ultima valutazione dell'albero (per data di
     * sopralluogo, poi di registrazione), fuori gli alberi rimossi. Le date
     * sono quelle del cruscotto (fuso italiano): i numeri devono tornare.
     */
    public static function conVta(Builder $query, string $stato): Builder
    {
        $oggi = Carbon::now(CoseDaFare::TIMEZONE)->toDateString();
        $fra30 = Carbon::now(CoseDaFare::TIMEZONE)->addDays(30)->toDateString();
        $ultima = '(SELECT ta.next_check_due FROM tree_assessments ta'
            .' WHERE ta.tree_id = assets.id AND ta.deleted_at IS NULL'
            .' ORDER BY ta.assessed_on DESC, ta.created_at DESC LIMIT 1)';
        $valutato = 'EXISTS (SELECT 1 FROM tree_assessments ta WHERE ta.tree_id = assets.id AND ta.deleted_at IS NULL)';

        $query->whereHas('tree', fn ($t) => $t->whereNull('removed_on'));

        return match ($stato) {
            'mai' => $query->whereRaw("NOT {$valutato}"),
            'valutato' => $query->whereRaw($valutato),
            'scaduta' => $query->whereRaw("{$ultima} < ?", [$oggi]),
            'in_scadenza' => $query->whereRaw("{$ultima} BETWEEN ? AND ?", [$oggi, $fra30]),
            default => $query,
        };
    }

    /** Alberi in gestione senza specie: le schede da completare. */
    public static function senzaSpecie(Builder $query): Builder
    {
        return $query->whereHas('tree', fn ($t) => $t->whereNull('removed_on')
            ->where(fn ($w) => $w->whereNull('species')->orWhere('species', '')));
    }
}
