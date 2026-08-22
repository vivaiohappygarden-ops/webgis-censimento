<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Organization;
use Illuminate\Console\Command;

/**
 * Elenca l'indirizzo pubblico di ogni committente e, su richiesta, dice se il
 * DNS è già stato creato. Serve dopo aver collegato il dominio dei portali:
 * chi gestisce il server vede in un colpo d'occhio cosa manca.
 */
class PortalAddresses extends Command
{
    protected $signature = 'portale:indirizzi
        {--verifica : Controlla nel DNS se ogni indirizzo punta a questo server}
        {--ip= : Indirizzo IP di questo server, usato dal controllo del DNS}
        {--solo-nomi : Stampa solo gli indirizzi dei portali accesi, uno per riga}';

    protected $description = 'Elenca gli indirizzi pubblici dei portali dei committenti';

    public function handle(): int
    {
        $base = trim((string) config('portal.base_host'));

        $committenti = Client::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNotNull('public_slug')
            ->whereIn('tenant_id', Organization::query()->withoutGlobalScopes()
                ->whereNull('deleted_at')->where('is_active', true)->select('id'))
            ->orderBy('name')
            ->get();

        if ($this->option('solo-nomi')) {
            foreach ($committenti as $committente) {
                if ($committente->portalIsLive() && $base !== '') {
                    $this->line($committente->public_slug.'.'.$base);
                }
            }

            return self::SUCCESS;
        }

        if ($committenti->isEmpty()) {
            $this->info('Nessun committente ha ancora un indirizzo pubblico.');

            return self::SUCCESS;
        }

        if ($base === '') {
            $this->warn('Il dominio dei portali non è configurato: gli indirizzi qui sotto');
            $this->warn('sono quelli di collaudo. Per averli come nome-comune.tuodominio.it:');
            $this->warn('  bash /var/www/webgis/deploy/set-portal-domain.sh tuodominio.it');
            $this->newLine();
        }

        $ipServer = trim((string) $this->option('ip'));
        $righe = [];

        foreach ($committenti as $committente) {
            $riga = [
                $committente->publicName(),
                $committente->portalIsLive() ? 'acceso' : 'spento',
                (string) $committente->portalUrl(),
            ];

            if ($this->option('verifica')) {
                $riga[] = $base === ''
                    ? 'non applicabile'
                    : $this->statoDns($committente->public_slug.'.'.$base, $ipServer);
            }

            $righe[] = $riga;
        }

        $intestazioni = ['Committente', 'Portale', 'Indirizzo'];
        if ($this->option('verifica')) {
            $intestazioni[] = 'DNS';
        }

        $this->table($intestazioni, $righe);

        if ($this->option('verifica') && $base !== '' && $ipServer !== '') {
            $this->newLine();
            $this->line('Per i "da creare", nel pannello del gestore del dominio:');
            $this->line("  Nome: <nome-comune>   Tipo: A   Valore: {$ipServer}");
            $this->line('Oppure un solo record jolly "*" che li copre tutti.');
        }

        return self::SUCCESS;
    }

    /** Dice se il nome è già registrato e se punta a questo server. */
    private function statoDns(string $nome, string $ipServer): string
    {
        $risolto = gethostbyname($nome);

        if ($risolto === $nome) {
            return 'da creare';
        }

        if ($ipServer !== '' && $risolto !== $ipServer) {
            return 'punta a '.$risolto;
        }

        return 'ok';
    }
}
