<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Da' (o toglie) a un utente la qualifica di gestore della piattaforma: chi
 * ce l'ha vede la console con tutte le organizzazioni. Si assegna solo da
 * qui, dal terminale del server: nessuna pagina puo' darla.
 *
 *   php artisan piattaforma:gestore titolare@happygarden.it
 *   php artisan piattaforma:gestore titolare@happygarden.it --togli
 */
class GestorePiattaforma extends Command
{
    protected $signature = 'piattaforma:gestore
        {email : Email dell\'utente}
        {--organizzazione= : Slug dell\'organizzazione, se l\'email e\' presente in piu\' di una}
        {--togli : Toglie la qualifica invece di darla}';

    protected $description = 'Da\' o toglie a un utente la qualifica di gestore della piattaforma (console di tutte le organizzazioni)';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $utenti = User::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('email', $email);
        if ($slug = $this->option('organizzazione')) {
            $utenti->whereIn('tenant_id', Organization::query()->where('slug', $slug)->select('id'));
        }
        $utenti = $utenti->get();

        if ($utenti->isEmpty()) {
            $this->error("Nessun utente con email {$email}".($slug ? " nell'organizzazione '{$slug}'" : '').'.');

            return self::FAILURE;
        }
        if ($utenti->count() > 1) {
            $this->error("L'email {$email} e' presente in piu' organizzazioni: indica --organizzazione=<slug>.");
            foreach ($utenti as $u) {
                $this->line('  - '.Organization::query()->find($u->tenant_id)?->slug);
            }

            return self::FAILURE;
        }

        /** @var User $utente */
        $utente = $utenti->first();
        $togli = (bool) $this->option('togli');
        $utente->forceFill(['is_platform_manager' => ! $togli])->save();

        $organizzazione = Organization::query()->find($utente->tenant_id);
        $this->info($togli
            ? "{$utente->name} ({$email}, {$organizzazione?->name}) non e' piu' gestore della piattaforma."
            : "{$utente->name} ({$email}, {$organizzazione?->name}) e' gestore della piattaforma: trova la console nel menu, con la verifica in due passaggi attiva.");

        return self::SUCCESS;
    }
}
