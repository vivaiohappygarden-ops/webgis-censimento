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

            Audit::log('vta.validated', $perizia);

            return $perizia->fresh(['assessor:id,name', 'validator:id,name']);
        });
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
