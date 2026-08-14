<?php

namespace App\Console\Commands;

use App\Mail\DailyDigestMail;
use App\Models\Organization;
use App\Services\Notifications\DailyDigest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Invio del riepilogo mattutino delle scadenze, tenant per tenant.
 * Gira ogni giorno dallo scheduler; --tenant limita a una sola
 * organizzazione (utile in prova).
 */
class SendDailyDigest extends Command
{
    protected $signature = 'notifications:daily {--tenant= : ID della sola organizzazione da elaborare}';

    protected $description = 'Invia il riepilogo email delle scadenze ad amministratori e tecnici';

    public function handle(DailyDigest $digest): int
    {
        $organizations = Organization::query()
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('name')->get();

        $sent = 0;
        foreach ($organizations as $organization) {
            $recipients = $digest->recipients($organization);
            if ($recipients->isEmpty()) {
                $this->line("{$organization->name}: nessun destinatario, salto.");

                continue;
            }

            $data = $digest->build($organization, $recipients->first());
            if ($data === null) {
                $this->line("{$organization->name}: niente da segnalare, nessuna email.");

                continue;
            }

            foreach ($recipients as $recipient) {
                // Un invio per destinatario: gli indirizzi non si vedono
                // tra loro e un rifiuto non blocca gli altri
                try {
                    Mail::to($recipient->email)->send(new DailyDigestMail($organization, $data));
                    $sent++;
                } catch (\Throwable $e) {
                    report($e);
                    $this->error("{$organization->name}: invio a {$recipient->email} non riuscito ({$e->getMessage()}).");
                }
            }
            $this->info("{$organization->name}: riepilogo inviato a {$recipients->count()} destinatari.");
        }

        $this->info("Totale email inviate: {$sent}.");

        return self::SUCCESS;
    }
}
