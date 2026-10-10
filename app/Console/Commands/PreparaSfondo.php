<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Mappe\SfondoOffline;
use Illuminate\Console\Command;

/**
 * Prepara lo sfondo della mappa per l'uso senza rete: per ogni organizzazione
 * ritaglia dalle costruzioni Protomaps le tessere del suo territorio e le
 * conserva sul server, pronte per i telefoni. Gira ogni notte dallo
 * scheduler (rifa' solo gli sfondi piu' vecchi di sfondo.giorni_validita) e
 * si lancia a mano con --forza quando serve subito.
 */
class PreparaSfondo extends Command
{
    protected $signature = 'sfondo:prepara
        {--organizzazione= : Lo slug dell\'organizzazione (senza, serve --tutte)}
        {--tutte : Tutte le organizzazioni attive}
        {--sorgente= : Un indirizzo https o un file .pmtiles al posto dell\'ultima costruzione Protomaps}
        {--zoom-max= : Zoom massimo delle tessere (di serie quello di config/sfondo.php)}
        {--forza : Rifa\' lo sfondo anche se e\' recente}';

    protected $description = 'Ritaglia lo sfondo cartografico del territorio di ogni organizzazione per l\'app di campo senza rete';

    public function handle(SfondoOffline $sfondo): int
    {
        $slug = $this->option('organizzazione');
        if (! $slug && ! $this->option('tutte')) {
            $this->error('Indica --organizzazione=<slug> oppure --tutte.');

            return self::INVALID;
        }

        $organizzazioni = Organization::query()
            ->when($slug, fn ($q) => $q->where('slug', $slug))
            ->when(! $slug, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();
        if ($organizzazioni->isEmpty()) {
            $this->error($slug ? "Organizzazione \"$slug\" non trovata." : 'Nessuna organizzazione attiva.');

            return self::FAILURE;
        }

        $zoomMax = $this->option('zoom-max') !== null ? (int) $this->option('zoom-max') : null;
        $falliti = 0;
        foreach ($organizzazioni as $organizzazione) {
            $this->line("== {$organizzazione->name} ({$organizzazione->slug})");
            // Un'organizzazione appena nata non ha ancora un territorio: non e' un errore
            if ($sfondo->riquadroTerritorio($organizzazione->id) === null) {
                $this->line('   Nessuna area ne\' elemento posizionato: niente territorio da ritagliare, per ora.');

                continue;
            }
            if (! $this->option('forza') && $sfondo->eRecente($organizzazione->id)) {
                $stato = $sfondo->stato($organizzazione->id);
                $this->line('   Sfondo recente ('.substr((string) ($stato['generato_il'] ?? ''), 0, 10).', '.($stato['versione'] ?? '').'): non si rifa\'. Usa --forza per rifarlo.');

                continue;
            }
            try {
                $sfondo->prepara($organizzazione, $this->option('sorgente') ?: null, fn (string $riga) => $this->line("   $riga"), $zoomMax);
            } catch (\Throwable $e) {
                $falliti++;
                $this->error('   Non riuscito: '.$e->getMessage());
            }
        }

        return $falliti === 0 ? self::SUCCESS : self::FAILURE;
    }
}
