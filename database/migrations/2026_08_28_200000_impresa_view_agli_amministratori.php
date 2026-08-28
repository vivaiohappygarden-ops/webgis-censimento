<?php

use App\Models\Organization;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Come per i tenant nuovi: l'amministratore ha tutto, portale imprese
 * compreso. La migrazione precedente creava il ruolo "impresa" ma
 * dimenticava di dare impresa.view agli amministratori delle
 * organizzazioni gia' esistenti (la gemella del portale cliente lo
 * faceva). Idempotente: rieseguirla non cambia niente.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        try {
            foreach (Organization::query()->pluck('id') as $tenantId) {
                $registrar->setPermissionsTeamId($tenantId);
                Role::query()->where('tenant_id', $tenantId)
                    ->where('name', 'amministratore')
                    ->first()?->givePermissionTo('impresa.view');
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }
    }

    public function down(): void
    {
        // Mirato come l'andata: togliere il permesso non serve, e revocarlo
        // alla cieca toccherebbe personalizzazioni dell'amministratore
    }
};
