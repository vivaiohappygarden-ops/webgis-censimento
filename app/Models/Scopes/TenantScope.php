<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // hasUser() non innesca la risoluzione dell'utente: chiamare Auth::user()
        // qui causerebbe ricorsione infinita quando la guard sta recuperando
        // l'utente stesso dal database (lo scope è attivo anche sul modello User).
        if (! Auth::hasUser()) {
            return;
        }

        $tenantId = Auth::user()->tenant_id;

        if ($tenantId !== null) {
            $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
        }
    }
}
