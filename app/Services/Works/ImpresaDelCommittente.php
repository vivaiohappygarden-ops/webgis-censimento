<?php

namespace App\Services\Works;

use App\Models\Team;
use Illuminate\Validation\ValidationException;

/**
 * Un'impresa esterna appartiene a un committente (indicazione 28/08/2026):
 * le si affidano solo lavori di QUEL committente. Le squadre interne
 * lavorano per chiunque.
 *
 * La regola sta qui una volta sola: la usano gli ordini di lavoro
 * (WorkOrderController), i piani di manutenzione alla creazione e il
 * generatore degli ordini dai piani. Tre copie divergerebbero.
 */
class ImpresaDelCommittente
{
    /**
     * Il motivo per cui la squadra NON e' ammissibile per quel committente,
     * o null se va bene. Il generatore lo usa senza eccezioni: un piano con
     * la squadra fuori regola genera comunque l'ordine, solo senza squadra.
     */
    public static function motivoEsclusione(?Team $team, ?string $clientId): ?string
    {
        if ($team === null || ! $team->is_external) {
            return null;
        }
        if ($team->client_id === null) {
            return "L'impresa \"{$team->name}\" non è ancora collegata a un committente: collegala dalla pagina Utenti prima di affidarle lavori.";
        }
        // La relazione puo' essere nulla anche con client_id valorizzato
        // (committente eliminato in passato): mai un errore grezzo
        $nomeCommittente = $team->client?->name;
        if ($nomeCommittente === null) {
            return "L'impresa \"{$team->name}\" è collegata a un committente eliminato: sistemala dalla pagina Utenti.";
        }
        if ($clientId !== $team->client_id) {
            return "L'impresa \"{$team->name}\" lavora per {$nomeCommittente}: le si affidano solo lavori di quel committente.";
        }

        return null;
    }

    /** Come motivoEsclusione, ma blocca la richiesta (creazione e modifica). */
    public static function verifica(?string $teamId, ?string $clientId): void
    {
        if ($teamId === null) {
            return;
        }
        $team = Team::query()->with('client:id,name')->find($teamId);
        $motivo = self::motivoEsclusione($team, $clientId);
        if ($motivo !== null) {
            throw ValidationException::withMessages(['team_id' => $motivo]);
        }
    }
}
