<?php

namespace App\Console\Commands;

use App\Services\Tenancy\CreatoreOrganizzazione;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Crea una nuova organizzazione pronta all'uso: ruoli, catalogo MD v2.1
 * e amministratore con password generata. È il comando del go-live; la
 * stessa procedura si lancia dalla console della piattaforma.
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

    public function handle(CreatoreOrganizzazione $creatore): int
    {
        try {
            $esito = $creatore->crea(
                $this->argument('name'),
                $this->argument('slug'),
                $this->argument('email'),
                $this->option('admin-name') ?: null,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $organization = $esito['organizzazione'];
        $counts = $esito['catalogo'];

        $this->info("Organizzazione '{$organization->name}' creata (slug: {$organization->slug}).");
        $this->info(sprintf(
            'Catalogo MD v2.1 installato: %d macro-categorie, %d tipi secondari, %d tipi oggetto.',
            $counts['main_types'], $counts['sub_types'], $counts['object_types'],
        ));
        $this->newLine();
        $this->line('Credenziali amministratore (comunicarle in modo sicuro e cambiare la password al primo accesso):');
        $this->table(['Email', 'Password'], [[$esito['amministratore']->email, $esito['password']]]);

        return self::SUCCESS;
    }
}
