<?php

namespace App\Services\Trees;

use App\Models\TreeAssessment;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Validazione della perizia: il momento in cui diventa un atto.
 *
 * Prima della validazione la scheda è una bozza di lavoro e si corregge.
 * Dopo, il contenuto tecnico è bloccato dal database e il documento porta
 * numero di protocollo, data, nome di chi ha validato e impronta.
 *
 * Non si torna indietro: una perizia sbagliata si supera con una perizia
 * successiva, come qualunque atto. È la stessa scelta dei prodotti di
 * riferimento, e senza di essa il documento non prova niente.
 */
class PeriziaValidation
{
    /** Campi che formano il contenuto tecnico dell'atto. */
    private const CONTENUTO = [
        'tree_id', 'assessment_type', 'assessed_on', 'assessor_id', 'assessor_external',
        'defects', 'targets', 'failure_class', 'outcome', 'prescriptions',
        'next_check_due', 'survey',
    ];

    public static function valida(TreeAssessment $perizia, User $utente): TreeAssessment
    {
        return DB::transaction(function () use ($perizia, $utente) {
            $perizia = TreeAssessment::query()->lockForUpdate()->findOrFail($perizia->id);

            // Non abort_if: il messaggio verrebbe costruito comunque, anche
            // quando la condizione è falsa, leggendo una data che non c'è
            if ($perizia->validated_at !== null) {
                abort(409, 'Questa perizia è già stata validata il '
                    .$perizia->validated_at->timezone('Europe/Rome')->format('d/m/Y')
                    .' e non si può validare di nuovo.');
            }

            abort_if($perizia->failure_class === null, 422,
                'Prima di validare va indicata la classe di propensione al cedimento.');

            self::applica($perizia, $utente);

            return $perizia->fresh(['assessor:id,name', 'validator:id,name']);
        });
    }

    /**
     * Validazione di più perizie in una volta: la firma di fine lavoro sul
     * cantiere di un committente. Stesse regole della singola, ma chi non
     * può essere validata viene riportata con il motivo, non blocca le
     * altre. Con $prova = true si conta soltanto, senza scrivere: anteprima
     * ed esecuzione passano dallo stesso metodo, così non divergono mai.
     *
     * @param  list<string>  $ids  id delle perizie (già filtrate dal tenant)
     * @return array{validate: list<array{id: string, codice: ?string, protocollo: ?string}>, saltate: list<array{id: string, codice: ?string, motivo: string}>}
     */
    public static function validaTante(array $ids, User $utente, bool $prova = false): array
    {
        $validate = [];
        $saltate = [];

        DB::transaction(function () use ($ids, $utente, $prova, &$validate, &$saltate) {
            $perizie = TreeAssessment::query()
                ->whereIn('id', $ids)
                ->orderBy('assessed_on')->orderBy('created_at')
                ->lockForUpdate()
                ->get();
            // Il codice dell'albero serve solo per i messaggi: letto a parte
            // per non portare il lock anche sugli asset
            $codici = \App\Models\Asset::query()
                ->whereIn('id', $perizie->pluck('tree_id')->unique())
                ->pluck('census_code', 'id');

            foreach (array_diff($ids, $perizie->pluck('id')->all()) as $mancante) {
                $saltate[] = ['id' => $mancante, 'codice' => null, 'motivo' => 'Perizia non trovata.'];
            }

            foreach ($perizie as $perizia) {
                $codice = $codici[$perizia->tree_id] ?? null;
                $etichetta = 'VTA del '.$perizia->assessed_on?->format('d/m/Y');

                if ($perizia->validated_at !== null) {
                    $saltate[] = ['id' => $perizia->id, 'codice' => $codice,
                        'motivo' => $etichetta.': già validata il '
                            .$perizia->validated_at->timezone('Europe/Rome')->format('d/m/Y').'.'];

                    continue;
                }
                if ($perizia->failure_class === null) {
                    $saltate[] = ['id' => $perizia->id, 'codice' => $codice,
                        'motivo' => $etichetta.': manca la classe di propensione al cedimento.'];

                    continue;
                }

                if (! $prova) {
                    self::applica($perizia, $utente, multipla: true);
                }
                // In prova il protocollo può essere ancora vuoto: si assegna
                // solo alla validazione vera
                $validate[] = ['id' => $perizia->id, 'codice' => $codice,
                    'protocollo' => $perizia->report_number];
            }
        });

        return ['validate' => $validate, 'saltate' => $saltate];
    }

    /** Il gesto vero della validazione, comune alla singola e alla collettiva. */
    private static function applica(TreeAssessment $perizia, User $utente, bool $multipla = false): void
    {
        // Il protocollo si assegna qui se non c'è già: la validazione è il
        // momento in cui la perizia diventa un documento numerato
        if ($perizia->report_number === null) {
            $perizia->report_number = TreeAssessment::nextReportNumber($perizia->tenant_id);
            $perizia->report_issued_at = now();
        }

        $perizia->validated_at = now();
        $perizia->validated_by = $utente->id;
        $perizia->content_hash = self::impronta($perizia);
        $perizia->updated_by = $utente->id;
        $perizia->save();

        Audit::log('vta.validated', $perizia, $multipla ? ['multipla' => true] : []);
    }

    /**
     * Impronta del contenuto tecnico: la stessa perizia dà sempre la stessa
     * impronta, una perizia diversa ne dà un'altra. Si stampa sul documento
     * per poter dimostrare, anche fra anni, che quella copia è quella
     * validata.
     */
    public static function impronta(TreeAssessment $perizia): string
    {
        $dati = [];
        foreach (self::CONTENUTO as $campo) {
            $valore = $perizia->getAttribute($campo);
            if ($valore instanceof \DateTimeInterface) {
                $valore = $valore->format('Y-m-d');
            }
            $dati[$campo] = $valore;
        }
        $dati['report_number'] = $perizia->report_number;

        // Chiavi ordinate a ogni livello: l'impronta non deve dipendere
        // dall'ordine con cui i dati sono finiti nel campo
        return hash('sha256', json_encode(
            self::ordina($dati),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /** Verifica che il contenuto corrisponda ancora all'impronta registrata. */
    public static function integra(TreeAssessment $perizia): bool
    {
        return $perizia->content_hash !== null
            && hash_equals($perizia->content_hash, self::impronta($perizia));
    }

    private static function ordina(mixed $valore): mixed
    {
        if (! is_array($valore)) {
            return $valore;
        }

        $valore = array_map([self::class, 'ordina'], $valore);
        if (! array_is_list($valore)) {
            ksort($valore);
        }

        return $valore;
    }
}
