<?php

namespace App\Services\Vta;

use App\Mail\AvvisoCommittenteMail;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientAlert;
use App\Models\Organization;
use App\Models\TreeAssessment;
use App\Models\User;
use App\Support\Audit;
use App\Support\PortalLabels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * L'avviso al committente che nasce da una valutazione di stabilita'
 * (richiesta del committente 07/10/2026): se un albero con propensione al
 * cedimento elevata o estrema sta in un parco giochi o in un'altra area
 * frequentata, il tecnico avvisa il Comune perche' chiuda o interdica l'area
 * fino all'intervento. La richiesta entra fra le prescrizioni della
 * valutazione, parte via email a nome dell'organizzazione e resta registrata
 * (client_alerts) con la presa d'atto del committente dal portale riservato e
 * il rientro segnato dal tecnico.
 *
 * I destinatari sono quelli che il committente ha gia' nel programma: gli
 * utenti del suo portale riservato, la PEC e i contatti con un indirizzo.
 * Senza indirizzi l'avviso resta comunque nel portale riservato e la pagina
 * lo dice: non si finge un invio.
 */
final class AvvisoCommittente
{
    /** Le classi di propensione al cedimento per cui la scheda VTA propone l'avviso da sola. */
    public const CLASSI_RISCHIOSE = ['C/D', 'D'];

    /** @return array{id: string, nome: string, destinatari: list<array{email: string, nome: ?string, origine: string}>}|null */
    public static function committentePer(Asset $asset): ?array
    {
        $client = $asset->area_id ? PortalLabels::clientOfArea($asset->area_id) : null;
        if ($client === null) {
            return null;
        }

        return [
            'id' => $client->id,
            'nome' => $client->name,
            'destinatari' => self::destinatari($client)->all(),
        ];
    }

    /**
     * Gli indirizzi del committente, senza doppioni: utenti del portale
     * riservato (users.client_id), PEC, contatti con un'email.
     *
     * @return Collection<int, array{email: string, nome: ?string, origine: string}>
     */
    public static function destinatari(Client $client): Collection
    {
        $lista = collect();
        User::query()
            ->where('client_id', $client->id)->where('is_active', true)->whereNotNull('email')
            ->orderBy('name')->get(['id', 'name', 'email'])
            ->each(fn (User $u) => $lista->push(['email' => (string) $u->email, 'nome' => $u->name, 'origine' => 'portale']));
        if ($client->pec) {
            $lista->push(['email' => (string) $client->pec, 'nome' => $client->name, 'origine' => 'pec']);
        }
        foreach ((array) ($client->contacts ?? []) as $contatto) {
            $email = is_array($contatto) ? ($contatto['email'] ?? null) : null;
            if (is_string($email)) {
                $lista->push(['email' => trim($email), 'nome' => $contatto['nome'] ?? $contatto['name'] ?? null, 'origine' => 'contatto']);
            }
        }

        return $lista
            ->filter(fn (array $d) => filter_var($d['email'], FILTER_VALIDATE_EMAIL) !== false)
            ->unique(fn (array $d) => mb_strtolower($d['email']))
            ->values();
    }

    /** La frase che entra fra le prescrizioni e nell'email: il tecnico la puo' cambiare prima di registrare. */
    public static function testoProposto(Asset $asset, ?Area $area): string
    {
        $dove = $area ? "all'area \"{$area->name}\"" : "all'area attorno all'albero";
        $cartellino = $asset->census_code ?: 'senza cartellino';

        return "Interdire l'accesso {$dove} nel raggio di caduta dell'albero {$cartellino} fino all'esecuzione degli interventi prescritti.";
    }

    /** Le prescrizioni con dentro il testo dell'avviso, una riga sola anche se gia' presente. */
    public static function conPrescrizione(?string $prescrizioni, string $testo): string
    {
        $righe = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $prescrizioni) ?: []), fn ($r) => $r !== ''));
        $testo = trim($testo);
        if ($testo !== '' && ! in_array($testo, $righe, true)) {
            $righe[] = $testo;
        }

        return implode("\n", $righe);
    }

    /**
     * Crea l'avviso per una valutazione gia' salvata, manda le email (una per
     * destinatario, piu' la copia a chi avvisa) e registra tutto. Un invio
     * che non riesce non ferma gli altri: l'esito di ognuno resta nella riga.
     */
    public function invia(TreeAssessment $assessment, Asset $asset, User $daChi, ?string $testo, ?string $areaId): ClientAlert
    {
        $client = $asset->area_id ? PortalLabels::clientOfArea($asset->area_id) : null;
        if ($client === null) {
            throw ValidationException::withMessages([
                'avviso_committente' => 'L\'albero non sta sotto un committente: non c\'e\' nessuno a cui mandare l\'avviso.',
            ]);
        }
        $area = $areaId ? Area::query()->find($areaId) : null;
        $testo = trim((string) $testo) !== '' ? trim((string) $testo) : self::testoProposto($asset, $area);

        $avviso = ClientAlert::create([
            'tenant_id' => $asset->tenant_id,
            'client_id' => $client->id,
            'asset_id' => $asset->id,
            'assessment_id' => $assessment->id,
            'area_id' => $area?->id,
            'kind' => ClientAlert::KIND_AREA_CLOSURE,
            'failure_class' => $assessment->failure_class,
            'message' => $testo,
            'sent_by' => $daChi->id,
            'sent_at' => now(),
            'recipients' => [],
        ]);

        $organizzazione = Organization::query()->find($asset->tenant_id);
        $esiti = [];
        foreach (self::destinatari($client) as $destinatario) {
            try {
                Mail::to($destinatario['email'])->send(new AvvisoCommittenteMail($organizzazione, $avviso, $asset, $assessment, $destinatario));
                $esiti[] = $destinatario + ['esito' => 'inviata'];
            } catch (\Throwable $e) {
                report($e);
                $esiti[] = $destinatario + ['esito' => 'non_riuscita', 'errore' => mb_strimwidth($e->getMessage(), 0, 200, '…')];
            }
        }
        // La copia a chi avvisa: e' la sua prova di quello che e' partito
        if ($daChi->email && $esiti !== []) {
            try {
                Mail::to($daChi->email)->send(new AvvisoCommittenteMail($organizzazione, $avviso, $asset, $assessment,
                    ['email' => $daChi->email, 'nome' => $daChi->name, 'origine' => 'copia'], $esiti));
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $avviso->recipients = $esiti;
        $avviso->save();

        Audit::log('avviso.committente_inviato', $avviso, [
            'asset_id' => $asset->id,
            'assessment_id' => $assessment->id,
            'client_id' => $client->id,
            'failure_class' => $assessment->failure_class,
            'destinatari' => count($esiti),
            'inviati' => $avviso->inviati(),
        ]);

        return $avviso;
    }
}
