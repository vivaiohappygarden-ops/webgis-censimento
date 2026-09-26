<?php

namespace App\Services\Portale;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Client;
use App\Models\User;
use App\Models\WorkOrderAsset;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Il territorio che un utente del portale riservato puo' vedere: il suo
 * committente (il Comune a cui e' collegato) e le aree che gli appartengono,
 * con le regole che dicono quali ordini e quali segnalazioni sono "sue".
 *
 * Nate nel PortalController (agosto 2026), dal 26/09/2026 stanno qui perche'
 * le leggono anche la mappa, l'elenco degli elementi, i lavori e i documenti
 * del portale del Comune: la regola e' una sola, o le pagine mostrerebbero
 * territori diversi.
 */
final class TerritorioCommittente
{
    /** @param Collection<int, string> $areaIds */
    private function __construct(
        public readonly User $user,
        public readonly Client $client,
        public readonly Collection $areaIds,
    ) {}

    /** Il territorio dell'utente, o null se non e' collegato a un committente (o il collegamento e' cessato). */
    public static function perUtente(User $user): ?self
    {
        if ($user->client_id === null) {
            return null;
        }

        // Rapporto cessato (cliente eliminato o di un altro tenant): il
        // portale si chiude, non mostra dati orfani
        $client = Client::query()->find($user->client_id);
        if ($client === null) {
            return null;
        }

        $areaIds = Area::query()
            ->whereHas('locality.site', fn ($q) => $q->where('client_id', $user->client_id))
            ->pluck('id');

        return new self($user, $client, $areaIds);
    }

    /** Come perUtente, ma senza collegamento risponde con l'errore che il portale gia' mostra. */
    public static function richiesto(User $user): self
    {
        return self::perUtente($user) ?? throw ValidationException::withMessages([
            'client' => 'Questo utente non è collegato a un cliente: chiedere all\'amministratore.',
        ]);
    }

    /** Gli elementi del territorio (tutti: l'archivio lo esclude chi interroga, se vuole). */
    public function elementi(): Builder
    {
        return Asset::query()->whereIn('assets.area_id', $this->areaIds);
    }

    public function possiedeArea(?string $areaId): bool
    {
        return $areaId !== null && $this->areaIds->contains($areaId);
    }

    /** Elenco degli id delle aree per le interrogazioni scritte in SQL. */
    public function segnapostoAree(): string
    {
        return $this->areaIds->isEmpty() ? 'NULL' : implode(',', array_fill(0, $this->areaIds->count(), '?'));
    }

    /** @return list<string> */
    public function legamiAree(): array
    {
        return $this->areaIds->values()->all();
    }

    /**
     * Un ordine appartiene al portale solo se coerente col cliente: intestato
     * a lui, oppure su una sua area, oppure senza area ma con elementi suoi.
     * Un ordine "misto" (area di un altro cliente) resta fuori: mai rivelare
     * lavori o aree altrui.
     */
    public function ordini(Builder|QueryBuilder $w): void
    {
        $w->where('work_orders.client_id', $this->client->id)
            ->orWhereIn('work_orders.area_id', $this->areaIds)
            ->orWhere(fn ($q) => $q->whereNull('work_orders.area_id')
                ->whereIn('work_orders.id', WorkOrderAsset::query()
                    ->join('assets', 'assets.id', '=', 'work_order_assets.asset_id')
                    ->whereIn('assets.area_id', $this->areaIds)
                    ->select('work_order_assets.work_order_id')));
    }

    /**
     * Le segnalazioni del territorio: su una sua area, su un suo elemento, o
     * inviate dal suo portale. Le richieste del cliente contano anche senza
     * area indicata: "Segnalazioni aperte: 0" sopra la propria richiesta
     * aperta sarebbe una contraddizione in piena vista.
     */
    public function segnalazioni(Builder|QueryBuilder $w): void
    {
        $w->whereIn('issues.area_id', $this->areaIds)
            ->orWhere(fn ($q) => $q->whereNull('issues.area_id')
                ->whereIn('issues.asset_id', Asset::query()->whereIn('area_id', $this->areaIds)->select('id')))
            ->orWhere(fn ($q) => $q->where('issues.channel', 'client_portal')
                ->where('issues.client_id', $this->client->id));
    }
}
