<?php

namespace App\Services\Tenancy;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea i permessi globali (idempotente) e i ruoli base di una nuova organizzazione.
 * I ruoli sono per-tenant e personalizzabili in seguito dall'amministratore.
 */
class TenantProvisioner
{
    public const PERMISSIONS = [
        'catalog.view', 'catalog.manage',
        'areas.view', 'areas.create', 'areas.update', 'areas.delete',
        'assets.view', 'assets.create', 'assets.update', 'assets.delete',
        'clients.view', 'clients.manage',
        'works.view', 'works.manage', 'works.execute',
        'users.manage',
        'portal.view',
        'impresa.view',
    ];

    public const ROLES = [
        'amministratore' => self::PERMISSIONS,
        'tecnico' => [
            'catalog.view', 'areas.view', 'areas.create', 'areas.update',
            'assets.view', 'assets.create', 'assets.update', 'assets.delete', 'clients.view',
            'works.view', 'works.manage',
        ],
        'operatore' => ['catalog.view', 'areas.view', 'assets.view', 'assets.create', 'assets.update', 'works.view'],
        // Il cliente vede solo il SUO territorio, dal portale dedicato:
        // la lettura generale del censimento mostrerebbe gli altri clienti
        'cliente' => ['portal.view'],
        // L'impresa appaltatrice vede solo gli ordini affidati alle squadre
        // di cui fa parte, dal portale dedicato: niente censimento, niente
        // altri lavori, niente dati degli altri committenti
        'impresa' => ['impresa.view'],
        // Chi esegue i lavori per una ditta esterna (dal 27/09/2026): dall'app
        // di campo rendiconta i soli lavori affidati alla sua squadra (fatto,
        // quantita', foto, segnalazioni) e riceve sul telefono solo gli
        // elementi di quei lavori. Niente censimento, niente elenco dei lavori
        // altrui: e' la differenza con l'operatore, che censisce
        'esecutore' => ['works.execute'],
    ];

    public function ensurePermissions(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    public function provisionRoles(Organization $organization): void
    {
        $this->ensurePermissions();

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($organization->id);

        try {
            foreach (self::ROLES as $roleName => $permissions) {
                /** @var Role $role */
                $role = Role::firstOrCreate([
                    'name' => $roleName,
                    'guard_name' => 'web',
                    'tenant_id' => $organization->id,
                ]);
                $role->syncPermissions($permissions);
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }
    }
}
