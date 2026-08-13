<?php

namespace App\Services\Inspections;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Inspection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Scadenzario dei controlli ricorrenti: per ogni modello attivo con
 * periodicità, l'ultima ispezione di ciascun bersaglio e la data entro cui
 * ripeterla. Usato dalla pagina Ispezioni e dal cruscotto Oggi: la logica
 * deve restare una sola.
 */
class InspectionDeadlines
{
    public const TIMEZONE = 'Europe/Rome';

    /** @return Collection<int, array<string, mixed>> ordinata per scadenza */
    public function compute(): Collection
    {
        $rows = Inspection::query()
            ->join('inspection_templates as t', function ($join) {
                $join->on('t.id', '=', 'inspections.template_id')
                    ->on('t.tenant_id', '=', 'inspections.tenant_id');
            })
            ->whereNull('t.deleted_at')
            ->where('t.is_active', true)
            ->whereNotNull('t.frequency_days')
            ->whereNotNull('inspections.completed_at')
            ->groupBy('inspections.template_id', 't.code', 't.name', 't.frequency_days', 't.target',
                'inspections.asset_id', 'inspections.area_id')
            ->selectRaw('inspections.template_id, t.code AS template_code, t.name AS template_name,
                t.frequency_days, t.target, inspections.asset_id, inspections.area_id,
                MAX(inspections.completed_at) AS last_completed_at')
            ->get();

        // L'esistenza si verifica sull'id, non sull'etichetta: un elemento
        // senza codice censimento è legittimo e non deve sparire in silenzio
        // proprio dalla vista nata per far emergere i ritardi
        $assets = Asset::query()
            ->whereIn('id', $rows->pluck('asset_id')->filter())
            ->get(['id', 'census_code'])->keyBy('id');
        $areaLabels = Area::query()
            ->whereIn('id', $rows->pluck('area_id')->filter())->pluck('name', 'id');

        $today = Carbon::now(self::TIMEZONE)->startOfDay();

        return $rows->map(function ($row) use ($assets, $areaLabels, $today) {
            $isAsset = $row->asset_id !== null;
            $label = $isAsset
                ? (isset($assets[$row->asset_id])
                    ? ($assets[$row->asset_id]->census_code ?? 'Elemento '.substr($row->asset_id, 0, 8))
                    : null)
                : ($areaLabels[$row->area_id] ?? null);
            // Bersaglio eliminato dal censimento: nessun controllo da pianificare
            if ($label === null) {
                return null;
            }

            $last = Carbon::parse($row->last_completed_at)->timezone(self::TIMEZONE);
            $due = $last->copy()->startOfDay()->addDays($row->frequency_days);
            // round(): a cavallo dell'ora legale il divario tra mezzanotti
            // non è un numero intero di giorni
            $daysLeft = (int) round($today->diffInDays($due, false));

            return [
                'template_id' => $row->template_id,
                'template_code' => $row->template_code,
                'template_name' => $row->template_name,
                'frequency_days' => (int) $row->frequency_days,
                'target' => $isAsset ? 'asset' : 'area',
                'target_id' => $isAsset ? $row->asset_id : $row->area_id,
                'target_label' => $label,
                'last_completed_at' => Carbon::parse($row->last_completed_at)->toIso8601String(),
                'due_date' => $due->toDateString(),
                'days_left' => $daysLeft,
                'state' => $daysLeft < 0 ? 'overdue' : ($daysLeft <= 30 ? 'due_soon' : 'ok'),
            ];
        })->filter()->sortBy('due_date')->values();
    }
}
