<?php

namespace App\Models\Scopes;

use App\Support\PerimetroZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Lo scope del perimetro di zona: per l'utente di zona ogni modello
 * territoriale e' filtrato sui committenti delle sue zone, secondo la
 * strategia dichiarata dal modello (costante PERIMETRO_ZONA). Per l'utente
 * centrale non aggiunge niente.
 */
class ZonaScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! Auth::hasUser()) {
            return;
        }
        $clienti = PerimetroZone::clienti(Auth::user());
        if ($clienti === null) {
            return;
        }

        $sedi = fn (): Query => DB::table('sites')->select('id')->whereIn('client_id', $clienti);
        $localita = fn (): Query => DB::table('localities')->select('id')->whereIn('site_id', $sedi());
        $aree = fn (): Query => DB::table('areas')->select('id')->whereIn('locality_id', $localita());
        $elementi = fn (): Query => DB::table('assets')->select('id')->whereIn('area_id', $aree());
        $lavori = fn (): Query => DB::table('work_orders')->select('id')
            ->where(fn ($q) => $q->whereIn('client_id', $clienti)->orWhereIn('site_id', $sedi())->orWhereIn('area_id', $aree()));
        $c = fn (string $colonna): string => $model->qualifyColumn($colonna);

        match ($model::PERIMETRO_ZONA) {
            'id' => $builder->whereIn($c('id'), $clienti),
            'client_id' => $builder->whereIn($c('client_id'), $clienti),
            'client_id_o_nullo' => $builder->where(fn ($q) => $q->whereNull($c('client_id'))->orWhereIn($c('client_id'), $clienti)),
            'site_id' => $builder->whereIn($c('site_id'), $sedi()),
            'locality_id' => $builder->whereIn($c('locality_id'), $localita()),
            'area_id' => $builder->whereIn($c('area_id'), $aree()),
            'area_o_elemento' => $builder->where(fn ($q) => $q->whereIn($c('area_id'), $aree())->orWhereIn($c('asset_id'), $elementi())),
            'cliente_o_area' => $builder->where(fn ($q) => $q->whereIn($c('client_id'), $clienti)->orWhereIn($c('area_id'), $aree())),
            'asset_id' => $builder->whereIn($c('asset_id'), $elementi()),
            'asset_id_o_nullo' => $builder->where(fn ($q) => $q->whereNull($c('asset_id'))->orWhereIn($c('asset_id'), $elementi())),
            'tree_id' => $builder->whereIn($c('tree_id'), $elementi()),
            'lavoro' => $builder->where(fn ($q) => $q->whereIn($c('client_id'), $clienti)->orWhereIn($c('site_id'), $sedi())->orWhereIn($c('area_id'), $aree())),
            'work_order_id' => $builder->whereIn($c('work_order_id'), $lavori()),
            'segnalazione' => $builder->where(fn ($q) => $q->whereIn($c('client_id'), $clienti)->orWhereIn($c('area_id'), $aree())->orWhereIn($c('asset_id'), $elementi())),
            default => throw new \LogicException('Strategia di perimetro sconosciuta: '.$model::PERIMETRO_ZONA),
        };
    }
}
