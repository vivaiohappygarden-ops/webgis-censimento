<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rinumera i codici del censimento di un committente con il suo prefisso
 * (MEN-0001, MEN-0002, ...), in ordine di inserimento.
 *
 * È una VARIANTE, non una procedura ordinaria: i cartellini già stampati e
 * applicati in campo riportano il codice vecchio, quindi dopo una
 * rinumerazione vanno ristampati. Per questo il comando di suo non scrive
 * nulla: mostra cosa farebbe e cambia i dati solo con --conferma.
 *
 *   php artisan censimento:rinumera "Comune di Mentana"
 *   php artisan censimento:rinumera "Comune di Mentana" --conferma
 */
class RenumberCensusCodes extends Command
{
    protected $signature = 'censimento:rinumera
        {committente : Nome, codice o identificativo del committente}
        {--prefisso= : Prefisso da usare (default: quello del committente)}
        {--conferma : Applica davvero le modifiche}';

    protected $description = 'Rinumera i codici del censimento di un committente con il prefisso delle etichette';

    public function handle(): int
    {
        $cercato = trim((string) $this->argument('committente'));

        $client = Client::withoutGlobalScopes()->whereNull('deleted_at')
            ->where(function ($q) use ($cercato) {
                // Il confronto con id va fatto solo se il valore è davvero un
                // UUID: PostgreSQL rifiuta il confronto con testo qualunque
                if (\Illuminate\Support\Str::isUuid($cercato)) {
                    $q->orWhere('id', $cercato);
                }
                $q->orWhere('code', $cercato)
                    ->orWhere('public_slug', $cercato)
                    ->orWhere('name', 'ilike', $cercato);
            })
            ->first();

        if ($client === null) {
            $this->error("Committente non trovato: {$cercato}");

            return self::FAILURE;
        }

        $prefisso = strtoupper(trim((string) ($this->option('prefisso') ?: $client->label_prefix)));

        if (! preg_match('/^[A-Z0-9]{2,6}$/', $prefisso)) {
            $this->error('Prefisso mancante o non valido: impostalo sulla scheda del committente oppure passa --prefisso.');

            return self::FAILURE;
        }

        // Ordine di inserimento: il numero racconta la storia del censimento
        $elementi = Asset::withoutGlobalScopes()->whereNull('deleted_at')
            ->whereIn('area_id', DB::table('areas')
                ->join('localities', 'localities.id', '=', 'areas.locality_id')
                ->join('sites', 'sites.id', '=', 'localities.site_id')
                ->where('sites.client_id', $client->id)
                ->whereNull('areas.deleted_at')
                ->select('areas.id'))
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'census_code', 'attributes']);

        if ($elementi->isEmpty()) {
            $this->info("Nessun elemento censito per {$client->name}.");

            return self::SUCCESS;
        }

        $cambi = [];
        $n = 0;
        foreach ($elementi as $asset) {
            $nuovo = sprintf('%s-%04d', $prefisso, ++$n);
            if ($asset->census_code !== $nuovo) {
                $cambi[] = [$asset, $asset->census_code, $nuovo];
            }
        }

        $this->line("Committente: {$client->name}");
        $this->line('Elementi: '.$elementi->count().' - da rinumerare: '.count($cambi));

        foreach (array_slice($cambi, 0, 10) as [, $vecchio, $nuovo]) {
            $this->line('  '.($vecchio ?? 'senza codice').' -> '.$nuovo);
        }
        if (count($cambi) > 10) {
            $this->line('  ... e altri '.(count($cambi) - 10));
        }

        if (! $this->option('conferma')) {
            $this->warn('Prova a vuoto: nessuna modifica applicata. Ripeti con --conferma per applicarle.');
            $this->warn('Attenzione: i cartellini già stampati riporteranno il codice vecchio e andranno ristampati.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($cambi, $client, $prefisso, $n) {
            // I codici sono unici per impresa: passando da MEN-0007 a MEN-0003
            // si può incrociare un codice ancora occupato. Si libera tutto
            // prima e si riscrive dopo, dentro la stessa transazione
            foreach ($cambi as [$asset]) {
                DB::table('assets')->where('id', $asset->id)
                    ->update(['census_code' => null]);
            }

            foreach ($cambi as [$asset, $vecchio, $nuovo]) {
                $attributi = $asset->attributes ?? [];
                if ($vecchio !== null && ! isset($attributi['codice_precedente'])) {
                    $attributi['codice_precedente'] = $vecchio;
                }

                DB::table('assets')->where('id', $asset->id)->update([
                    'census_code' => $nuovo,
                    'attributes' => json_encode($attributi, JSON_UNESCAPED_UNICODE),
                ]);
            }

            // Il contatore non deve tornare indietro rispetto a quanto assegnato
            DB::table('clients')->where('id', $client->id)->update([
                'label_prefix' => $prefisso,
                'label_counter' => DB::raw('GREATEST(label_counter, '.(int) $n.')'),
            ]);

            // Da riga di comando non c'è un utente: il tenant lo prendiamo
            // dal committente, altrimenti la traccia resterebbe orfana
            \App\Models\AuditLog::create([
                'tenant_id' => $client->tenant_id,
                'action' => 'client.census_codes_renumbered',
                'subject_type' => $client->getMorphClass(),
                'subject_id' => $client->getKey(),
                'payload' => ['prefisso' => $prefisso, 'rinumerati' => count($cambi)],
            ]);
        });

        $this->info('Rinumerazione completata: '.count($cambi).' codici aggiornati.');
        $this->warn('Ricordati di ristampare i cartellini interessati.');

        return self::SUCCESS;
    }
}
