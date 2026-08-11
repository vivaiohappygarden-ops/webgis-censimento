<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use App\Services\Catalog\CatalogInstaller;
use App\Services\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea una nuova organizzazione pronta all'uso: ruoli, catalogo MD v2.1
 * e amministratore con password generata. È il comando del go-live.
 *
 *   php artisan tenant:create "Happy Garden" happy-garden admin@happygarden.it
 */
class CreateTenant extends Command
{
    protected $signature = 'tenant:create
        {name : Nome dell\'organizzazione}
        {slug : Identificativo breve (minuscole e trattini, usato al login)}
        {email : Email dell\'amministratore}
        {--admin-name= : Nome dell\'amministratore (default: Amministratore)}';

    protected $description = 'Crea un\'organizzazione con ruoli, catalogo Modello Dati v2.1 e utente amministratore';

    public function handle(): int
    {
        $slug = Str::slug($this->argument('slug'));
        $email = strtolower(trim($this->argument('email')));

        if ($slug === '') {
            $this->error('Slug non valido.');

            return self::FAILURE;
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Email non valida: {$email}");

            return self::FAILURE;
        }
        if (Organization::query()->where('slug', $slug)->exists()) {
            $this->error("Esiste già un'organizzazione con slug '{$slug}'.");

            return self::FAILURE;
        }

        $password = Str::password(16, symbols: false);

        [$organization, $counts] = DB::transaction(function () use ($slug, $email, $password) {
            $organization = Organization::create([
                'name' => $this->argument('name'),
                'slug' => $slug,
                'metric_srid' => 7791,
            ]);

            app(TenantProvisioner::class)->provisionRoles($organization);

            $admin = User::query()->newModelInstance([
                'name' => $this->option('admin-name') ?: 'Amministratore',
                'email' => $email,
                'password' => $password,
                'user_type' => 'internal',
            ]);
            $admin->tenant_id = $organization->id;
            $admin->save();

            app(PermissionRegistrar::class)->setPermissionsTeamId($organization->id);
            $admin->assignRole('amministratore');

            $counts = app(CatalogInstaller::class)->install($organization);

            return [$organization, $counts];
        });

        $this->info("Organizzazione '{$organization->name}' creata (slug: {$slug}).");
        $this->info(sprintf(
            'Catalogo MD v2.1 installato: %d macro-categorie, %d tipi secondari, %d tipi oggetto.',
            $counts['main_types'], $counts['sub_types'], $counts['object_types'],
        ));
        $this->newLine();
        $this->line('Credenziali amministratore (comunicarle in modo sicuro e cambiare la password al primo accesso):');
        $this->table(['Email', 'Password'], [[$email, $password]]);

        return self::SUCCESS;
    }
}
