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
        // Chiave composita: un utente non può essere agganciato a un
        // cliente di un'altra organizzazione, lo garantisce il database
        DB::statement('
            ALTER TABLE clients
            ADD CONSTRAINT uq_clients_tenant_id_id UNIQUE (tenant_id, id)
        ');
        DB::statement('
            ALTER TABLE users
            ADD COLUMN IF NOT EXISTS client_id uuid,
            ADD CONSTRAINT fk_users_tenant_client
                FOREIGN KEY (tenant_id, client_id) REFERENCES clients(tenant_id, id)
        ');

        app(TenantProvisioner::class)->ensurePermissions();

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();

        try {
            foreach (Organization::query()->pluck('id') as $tenantId) {
                $registrar->setPermissionsTeamId($tenantId);

                // Come per i tenant nuovi: l'amministratore ha tutto,
                // portale compreso
                Role::query()->where('tenant_id', $tenantId)->where('name', 'amministratore')
                    ->first()?->givePermissionTo('portal.view');

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
        // Rollback simmetrico: il ruolo cliente torna alla lettura di prima,
        // altrimenti resterebbe senza alcun permesso utilizzabile
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
                $role->givePermissionTo(['areas.view', 'assets.view']);
                if ($role->hasPermissionTo('portal.view')) {
                    $role->revokePermissionTo('portal.view');
                }
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS fk_users_tenant_client');
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS client_id');
        DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS uq_clients_tenant_id_id');
    }
};
