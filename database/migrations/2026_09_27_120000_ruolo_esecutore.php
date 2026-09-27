<?php

use App\Models\Organization;
use App\Models\Role;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ruolo "esecutore" (27/09/2026): chi lavora per una ditta esterna
 * rendiconta dal campo i soli lavori affidati alla sua squadra, senza il
 * censimento. Permesso works.execute; il ruolo nasce in ogni organizzazione
 * gia' esistente e l'amministratore riceve il permesso nuovo, come fu per il
 * portale delle imprese.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(TenantProvisioner::class)->ensurePermissions();
        // Il pacchetto dei permessi tiene una cache: senza svuotarla, il
        // permesso appena creato risulterebbe inesistente qui sotto
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        try {
            foreach (Organization::query()->pluck('id') as $tenantId) {
                $registrar->setPermissionsTeamId($tenantId);
                $role = Role::firstOrCreate([
                    'name' => 'esecutore', 'guard_name' => 'web', 'tenant_id' => $tenantId,
                ]);
                $role->givePermissionTo('works.execute');

                $amministratore = Role::query()->where('tenant_id', $tenantId)->where('name', 'amministratore')->first();
                $amministratore?->givePermissionTo('works.execute');
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }
    }

    public function down(): void
    {
        // Il ruolo resta: toglierlo lascerebbe utenti senza ruolo
    }
};
