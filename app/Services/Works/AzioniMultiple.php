<?php

namespace App\Services\Works;

use App\Models\Asset;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAsset;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Azioni su piu' elementi in una volta sola.
 *
 * Regola che vale per tutte: quello che non si e' potuto fare va detto, uno
 * per uno e con il motivo. Un'azione di gruppo che salta tre righe su venti
 * senza dirlo e' peggio di non averla: chi la usa crede di aver chiuso tutto.
 *
 * Il tetto di 500 elementi per richiesta non e' un limite di prudenza
 * generica: oltre quella soglia la transazione tiene bloccate troppe righe e
 * il resto del lavoro si ferma.
 */
class AzioniMultiple
{
    public const MASSIMO = 500;

    /**
     * Chiude piu' ordini di lavoro. Ognuno segue le sue regole di passaggio
     * di stato: quelli che non possono chiudersi vengono riportati con il
     * motivo, non forzati.
     *
     * @param  list<string>  $ids
     */
    public static function chiudiLavori(array $ids, User $utente, bool $prova = false): array
    {
        $fatti = [];
        $saltati = [];

        DB::transaction(function () use ($ids, $utente, $prova, &$fatti, &$saltati) {
            $ordini = WorkOrder::query()->whereIn('id', $ids)->lockForUpdate()->get();
            $trovati = $ordini->pluck('id')->all();

            foreach (array_diff($ids, $trovati) as $mancante) {
                $saltati[] = ['id' => $mancante, 'codice' => null, 'motivo' => 'Ordine non trovato.'];
            }

            foreach ($ordini as $ordine) {
                if ($ordine->status === 'completed') {
                    $saltati[] = ['id' => $ordine->id, 'codice' => $ordine->code, 'motivo' => 'Gia\' completato.'];

                    continue;
                }
                if (! $ordine->canTransitionTo('completed')) {
                    $saltati[] = ['id' => $ordine->id, 'codice' => $ordine->code,
                        'motivo' => "Da '{$ordine->status}' non si puo' passare a completato."];

                    continue;
                }

                // Prova a vuoto: si conta cosa succederebbe, senza toccare niente.
                // Cosi' prima di confermare si legge "N verranno chiusi, M esclusi"
                if (! $prova) {
                    $ordine->status = 'completed';
                    $ordine->completed_at = now();
                    $ordine->version += 1;
                    $ordine->updated_by = $utente->id;
                    $ordine->save();

                    Audit::log('work_order.transition', $ordine, ['to' => 'completed', 'multipla' => true]);
                }
                $fatti[] = ['id' => $ordine->id, 'codice' => $ordine->code];
            }
        });

        return ['completati' => $fatti, 'saltati' => $saltati];
    }

    /**
     * Campi della scheda albero che si possono cambiare in blocco, con
     * l'etichetta con cui si mostrano. L'elenco sta qui e basta: lo usano
     * la validazione dell'API, il riepilogo dell'anteprima e la finestra.
     */
    public const CAMPI_ALBERO = [
        'genus' => 'Genere',
        'species' => 'Specie',
        'cultivar' => 'Cultivar',
        'common_name' => 'Nome comune',
        'height_m' => 'Altezza (m)',
        'dbh_cm' => 'Diametro del tronco (cm)',
        'trunk_circumference_cm' => 'Circonferenza del tronco (cm)',
        'crown_diameter_m' => 'Diametro della chioma (m)',
        'crown_insertion_m' => 'Inserzione della chioma (m)',
        'trunk_count' => 'Numero di fusti',
        'age_years_est' => "Eta' stimata (anni)",
        'age_qualifier' => "Qualificatore dell'eta'",
        'age_class' => 'Fase fisiologica',
        'vegetative_state' => 'Stato vegetativo',
        'social_position' => 'Posizione sociale',
        'growth_site' => 'Sito di crescita',
        'target' => 'Bersaglio',
    ];

    /**
     * Modifica gli stessi campi su piu' elementi censiti.
     *
     * Si toccano solo campi che hanno senso in blocco: la visibilita' sul
     * portale pubblico e la data di rilievo. La geometria resta fuori perche'
     * e' il posto di quel singolo elemento; lo stato resta fuori perche'
     * l'abbattimento ha un suo flusso, e cambiarlo da qui lascerebbe data di
     * rimozione e scheda albero disallineate. Specie e misure hanno una
     * strada tutta loro, con le sue difese: modificaAlberi() qui sotto.
     *
     * @param  list<string>  $ids
     */
    public static function modificaElementi(array $ids, array $modifiche, User $utente, bool $prova = false): array
    {
        $fatti = [];
        $saltati = [];

        DB::transaction(function () use ($ids, $modifiche, $utente, $prova, &$fatti, &$saltati) {
            $elementi = Asset::query()->whereIn('id', $ids)->lockForUpdate()->get();

            foreach (array_diff($ids, $elementi->pluck('id')->all()) as $mancante) {
                $saltati[] = ['id' => $mancante, 'codice' => null, 'motivo' => 'Elemento non trovato.'];
            }

            foreach ($elementi as $elemento) {
                // Le schede in archivio (abbattute o dismesse) non si toccano
                // in blocco: prima si ripristinano. Il controllo sta nello
                // stesso giro della prova, cosi' anteprima ed esecuzione
                // contano uguale
                if (\App\Support\AssetStatus::inArchivio($elemento->status)) {
                    $saltati[] = ['id' => $elemento->id, 'codice' => $elemento->census_code,
                        'motivo' => 'In archivio ('.\App\Support\AssetStatus::label($elemento->status).'): si modifica solo dopo il ripristino.'];

                    continue;
                }

                if (! $prova) {
                    $elemento->fill($modifiche);
                    $elemento->version += 1;
                    $elemento->updated_by = $utente->id;
                    $elemento->save();

                    Audit::log('asset.updated', $elemento, ['multipla' => true, 'campi' => array_keys($modifiche)]);
                }
                $fatti[] = ['id' => $elemento->id, 'codice' => $elemento->census_code];
            }
        });

        return ['modificati' => $fatti, 'saltati' => $saltati];
    }

    /**
     * Collega piu' elementi a un ordine di lavoro gia' aperto. Gli elementi
     * gia' presenti con la stessa lavorazione non si duplicano: si saltano e
     * lo si dice.
     *
     * @param  list<string>  $ids
     */
    public static function collegaElementi(WorkOrder $ordine, array $ids, ?string $workTypeId = null, bool $prova = false): array
    {
        $fatti = [];
        $saltati = [];

        DB::transaction(function () use ($ordine, $ids, $workTypeId, $prova, &$fatti, &$saltati) {
            // Blocco della riga dell'ordine: due conferme quasi simultanee
            // sullo stesso ordine si mettono in fila, così la lista dei "già
            // presenti" letta sotto è affidabile e il vincolo di unicità non
            // fa mai saltare in aria l'intero blocco della seconda
            WorkOrder::query()->lockForUpdate()->findOrFail($ordine->id);

            $elementi = Asset::query()->whereIn('id', $ids)->get();

            foreach (array_diff($ids, $elementi->pluck('id')->all()) as $mancante) {
                $saltati[] = ['id' => $mancante, 'codice' => null, 'motivo' => 'Elemento non trovato.'];
            }

            $gia = WorkOrderAsset::query()
                ->where('work_order_id', $ordine->id)
                ->where('work_type_id', $workTypeId)
                ->pluck('asset_id')->all();

            // Anche l'aggancio in blocco propone la quantità dalla geometria,
            // come i percorsi uno-a-uno: senza, trenta prati collegati insieme
            // resterebbero fuori dal previsto e dalla proposta del consuntivo
            $lavorazione = $workTypeId !== null
                ? \App\Models\WorkType::query()->find($workTypeId)
                : null;
            $unita = $lavorazione?->unit ?? $ordine->workType?->unit;

            foreach ($elementi as $elemento) {
                // Un abbattuto o un dismesso non entra in un ordine di lavoro:
                // non e' piu' patrimonio su cui si lavora. Stesso giro della
                // prova, cosi' il pre-conteggio non mente
                if (\App\Support\AssetStatus::inArchivio($elemento->status)) {
                    $saltati[] = ['id' => $elemento->id, 'codice' => $elemento->census_code,
                        'motivo' => 'In archivio ('.\App\Support\AssetStatus::label($elemento->status).'): non si collega a un ordine di lavoro.'];

                    continue;
                }

                if (in_array($elemento->id, $gia, true)) {
                    $saltati[] = ['id' => $elemento->id, 'codice' => $elemento->census_code,
                        'motivo' => 'Gia\' presente nell\'ordine con questa lavorazione.'];

                    continue;
                }

                if (! $prova) {
                    $quantita = $unita !== null ? QuantitaDaGeometria::perAsset($unita, $elemento) : null;
                    WorkOrderAsset::create([
                        'tenant_id' => $ordine->tenant_id,
                        'work_order_id' => $ordine->id,
                        'asset_id' => $elemento->id,
                        'work_type_id' => $workTypeId,
                        'planned_quantity' => $quantita,
                        'unit' => $quantita !== null ? $unita : null,
                    ]);
                }

                $fatti[] = ['id' => $elemento->id, 'codice' => $elemento->census_code];
            }

            // Il registro racconta solo cose successe: niente audit in prova
            if (! $prova && $fatti !== []) {
                Audit::log('work_order.assets_attached', $ordine, ['quanti' => count($fatti)]);
            }
        });

        return ['collegati' => $fatti, 'saltati' => $saltati];
    }

    /**
     * Modifica specie e misure su piu' alberi in una volta.
     *
     * E' l'azione piu' pericolosa del programma: applicata alla selezione
     * sbagliata riscrive il censimento. Per questo ha tre difese, e nessuna
     * e' facoltativa:
     *
     *  1. si scrivono SOLO i campi scelti uno per uno (chi non compare nella
     *     richiesta non viene toccato, nemmeno per svuotarlo);
     *  2. con $soloVuoti si riempiono i buchi e basta: dove un valore c'e'
     *     gia', si salta e lo si dichiara. E' il modo giusto per completare
     *     un censimento importato senza cancellare il lavoro di nessuno;
     *  3. l'anteprima conta le righe che cambierebbero davvero, non quelle
     *     selezionate: un valore gia' uguale non e' una modifica.
     *
     * Ordine di scrittura obbligato (vedi CLAUDE.md): prima si prepara la
     * scheda albero senza salvarla, poi si incrementa la versione della
     * scheda (li' scatta la fotografia dello storico, che deve riprendere i
     * valori VECCHI), e solo alla fine si salva l'albero. Invertirlo fa
     * mentire lo storico.
     *
     * @param  list<string>  $ids
     * @param  array<string, mixed>  $campi
     * @return array{modificati: list<array>, saltati: list<array>}
     */
    public static function modificaAlberi(array $ids, array $campi, bool $soloVuoti, User $utente, bool $prova = false): array
    {
        $fatti = [];
        $saltati = [];

        DB::transaction(function () use ($ids, $campi, $soloVuoti, $utente, $prova, &$fatti, &$saltati) {
            $elementi = Asset::query()->with('tree')->whereIn('id', $ids)->lockForUpdate()->get();

            foreach (array_diff($ids, $elementi->pluck('id')->all()) as $mancante) {
                $saltati[] = ['id' => $mancante, 'codice' => null, 'motivo' => 'Elemento non trovato.'];
            }

            foreach ($elementi as $elemento) {
                $base = ['id' => $elemento->id, 'codice' => $elemento->census_code];

                if (\App\Support\AssetStatus::inArchivio($elemento->status)) {
                    $saltati[] = [...$base,
                        'motivo' => 'In archivio ('.\App\Support\AssetStatus::label($elemento->status).'): si modifica solo dopo il ripristino.'];

                    continue;
                }
                if ($elemento->tree === null) {
                    $saltati[] = [...$base, 'motivo' => "Non e' una scheda albero."];

                    continue;
                }

                [$daScrivere, $motivi] = self::campiDaScrivere($elemento->tree, $campi, $soloVuoti);

                if ($daScrivere === []) {
                    $saltati[] = [...$base, 'motivo' => implode(' ', $motivi) ?: 'Nessun campo da cambiare.'];

                    continue;
                }

                if (! $prova) {
                    // 1. si prepara la specializzazione, senza salvarla
                    $elemento->tree->fill($daScrivere);

                    // 2. si incrementa la versione della scheda: la fotografia
                    //    dello storico scatta qui e riprende i valori vecchi
                    DB::update('UPDATE assets SET version = version + 1, updated_at = now(), updated_by = ? WHERE id = ?', [
                        $utente->id, $elemento->id,
                    ]);

                    // 3. e solo adesso si salva l'albero
                    $elemento->tree->save();

                    Audit::log('asset.updated', $elemento, [
                        'multipla' => true,
                        'campi_albero' => array_keys($daScrivere),
                        'solo_vuoti' => $soloVuoti,
                    ]);
                }

                $fatti[] = [...$base, 'campi' => array_keys($daScrivere)];
            }
        });

        return ['modificati' => $fatti, 'saltati' => $saltati];
    }

    /**
     * Che cosa cambierebbe davvero su questo albero, e perche' il resto no.
     *
     * Lo usano anteprima ed esecuzione (e' lo stesso giro): un valore gia'
     * uguale non e' una modifica, e in modalita' "solo i vuoti" un campo
     * gia' compilato si rispetta.
     *
     * @param  array<string, mixed>  $campi
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function campiDaScrivere(\App\Models\Tree $albero, array $campi, bool $soloVuoti): array
    {
        $daScrivere = [];
        $motivi = [];

        foreach ($campi as $campo => $valore) {
            $attuale = $albero->{$campo};
            $vuoto = $attuale === null || $attuale === '';

            if ($soloVuoti && ! $vuoto) {
                $motivi[] = (self::CAMPI_ALBERO[$campo] ?? $campo).": c'e' gia' un valore.";

                continue;
            }

            // Il confronto passa dal casting del modello (i decimali arrivano
            // dal database come stringhe): senza, "38" e 38.0 sembrerebbero
            // due valori diversi e l'anteprima conterebbe modifiche finte
            $uguale = $vuoto
                ? ($valore === null || $valore === '')
                : (string) $attuale === (string) $albero->newInstance()->forceFill([$campo => $valore])->{$campo};

            if ($uguale) {
                $motivi[] = (self::CAMPI_ALBERO[$campo] ?? $campo).': valore gia\' uguale.';

                continue;
            }

            $daScrivere[$campo] = $valore;
        }

        return [$daScrivere, $motivi];
    }
}
