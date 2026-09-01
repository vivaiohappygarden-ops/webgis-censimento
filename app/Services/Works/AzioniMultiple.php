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
     * Modifica gli stessi campi su piu' elementi censiti.
     *
     * Si toccano solo campi che hanno senso in blocco: la visibilita' sul
     * portale pubblico e la data di rilievo. Specie, misure e geometria
     * restano fuori perche' sono dati del singolo albero; lo stato resta
     * fuori perche' l'abbattimento ha un suo flusso, e cambiarlo da qui
     * lascerebbe data di rimozione e scheda albero disallineate.
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
}
