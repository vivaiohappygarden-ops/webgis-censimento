<?php

use App\Models\Organization;
use App\Models\Role;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Portale cliente: gli utenti col ruolo cliente vengono agganciati al LORO
 * cliente (users.client_id) e vedono solo il proprio territorio dal
 * portale. Il ruolo perde la lettura generale del censimento: con più
 * clienti nello stesso tenant vedrebbe i dati degli altri.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            ALTER TABLE users
            ADD COLUMN IF NOT EXISTS client_id uuid REFERENCES clients(id)
        ');

        app(TenantProvisioner::class)->ensurePermissions();

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();

        try {
            foreach (Organization::query()->pluck('id') as $tenantId) {
                $registrar->setPermissionsTeamId($tenantId);

                $role = Role::query()
                    ->where('tenant_id', $tenantId)
                    ->where('name', 'cliente')
                    ->first();
                if ($role === null) {
                    continue;
                }
                // Mirato, non syncPermissions: eventuali altre personalizzazioni
                // dell'amministratore restano intatte
                $role->givePermissionTo('portal.view');
                foreach (['areas.view', 'assets.view'] as $permission) {
                    if ($role->hasPermissionTo($permission)) {
                        $role->revokePermissionTo($permission);
                    }
                }
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS client_id');
    }
};
