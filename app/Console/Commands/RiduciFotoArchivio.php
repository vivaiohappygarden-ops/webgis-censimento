<?php

namespace App\Console\Commands;

use App\Models\Photo;
use App\Models\TreeAssessment;
use App\Services\Photos\ImageDerivative;
use App\Services\Photos\PublicPhotoCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Riduce le fotografie gia' in archivio che erano state caricate intere dal
 * computer (prima del 26/09/2026 si salvavano come arrivavano, anche 5-15 MB
 * l'una), con la stessa regola che oggi vale al caricamento:
 * ImageDerivative::perArchivio (2000 px sul lato lungo, JPEG, raddrizzate).
 *
 * Senza --esegui mostra soltanto che cosa farebbe e quanto spazio si
 * recupera. Le foto che stanno negli atti di una perizia validata non si
 * toccano: una perizia validata ristampata deve dare lo stesso foglio, e
 * cambiare i pixel di una sua fotografia lo cambierebbe.
 */
class RiduciFotoArchivio extends Command
{
    protected $signature = 'foto:riduci-archivio
        {--esegui : Riscrive davvero i file (senza, mostra solo che cosa farebbe)}
        {--limite=0 : Quante foto esaminare al massimo (0 = tutte)}';

    protected $description = 'Riduce le fotografie in archivio caricate intere (2000 px, JPEG), come avviene oggi al caricamento';

    public function handle(): int
    {
        $esegui = (bool) $this->option('esegui');
        $limite = max(0, (int) $this->option('limite'));
        $disk = Storage::disk();

        // Le foto che erano gia' negli atti quando una perizia e' stata
        // validata: per ogni elemento, la validazione piu' recente
        $congelate = TreeAssessment::query()->withoutGlobalScopes()
            ->whereNotNull('validated_at')->whereNull('deleted_at')
            ->selectRaw('tree_id, MAX(validated_at) AS validata')
            ->groupBy('tree_id')
            ->pluck('validata', 'tree_id');

        // Tutte le foto vive, dalla piu' pesante: la decisione vera la prende
        // la stessa regola del caricamento (perArchivio), ma leggere ogni file
        // per intero costerebbe ore. Si scartano al volo quelle che non possono
        // essere candidate: JPEG sotto la soglia di peso e, quando il file e'
        // sul disco locale, con il lato lungo gia' entro il massimo (si legge
        // solo l'intestazione)
        $query = Photo::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->orderByDesc('size_bytes')->orderBy('id');
        if ($limite > 0) {
            $query->limit($limite);
        }

        $conteggi = ['esaminate' => 0, 'ridotte' => 0, 'gia_buone' => 0, 'negli_atti' => 0, 'non_riducibili' => 0, 'mancanti' => 0];
        $prima = 0;
        $dopo = 0;

        foreach ($query->cursor() as $foto) {
            $conteggi['esaminate']++;

            if (! $this->potrebbeRidursi($foto)) {
                $conteggi['gia_buone']++;

                continue;
            }

            $validata = $congelate[$foto->asset_id] ?? null;
            if ($validata !== null && $foto->created_at !== null && $foto->created_at->lessThanOrEqualTo($validata)) {
                $conteggi['negli_atti']++;

                continue;
            }
            if (! $disk->exists($foto->s3_key)) {
                $conteggi['mancanti']++;

                continue;
            }

            $originale = (string) $disk->get($foto->s3_key);
            $ridotta = ImageDerivative::perArchivio($originale);
            if ($ridotta === null) {
                $conteggi['non_riducibili']++;

                continue;
            }

            $conteggi['ridotte']++;
            $prima += strlen($originale);
            $dopo += strlen($ridotta);
            $this->line(sprintf('  %s  %s -> %s  %s', $foto->id, $this->mb(strlen($originale)), $this->mb(strlen($ridotta)),
                $esegui ? 'ridotta' : 'verrebbe ridotta'));

            if (! $esegui) {
                continue;
            }

            $this->sostituisci($foto, $ridotta);
        }

        $this->newLine();
        $this->table(['Esaminate', 'Ridotte', 'Gia\' buone', 'Negli atti (saltate)', 'Non riducibili', 'File mancanti', 'Prima', 'Dopo'], [[
            $conteggi['esaminate'], $conteggi['ridotte'], $conteggi['gia_buone'], $conteggi['negli_atti'], $conteggi['non_riducibili'], $conteggi['mancanti'],
            $this->mb($prima), $this->mb($dopo),
        ]]);
        if (! $esegui) {
            $this->info('Anteprima: nessun file e\' stato toccato. Per farlo davvero: php artisan foto:riduci-archivio --esegui');
        } else {
            Log::info('foto:riduci-archivio', $conteggi + ['byte_prima' => $prima, 'byte_dopo' => $dopo]);
            $this->info(sprintf('Fatto: recuperati %s.', $this->mb($prima - $dopo)));
        }

        return self::SUCCESS;
    }

    /** Scarto rapido: pesante o non JPEG e' candidata; altrimenti decide il lato lungo, letto dalla sola intestazione. */
    private function potrebbeRidursi(Photo $foto): bool
    {
        if ((int) $foto->size_bytes > ImageDerivative::BYTE_ARCHIVIO || $foto->mime_type !== 'image/jpeg') {
            return true;
        }

        try {
            $percorso = Storage::disk()->path($foto->s3_key);
        } catch (\Throwable) {
            // Disco remoto (S3): l'intestazione non si legge senza scaricare il
            // file. Le foto leggere restano come sono: il risparmio sta nelle grosse
            return false;
        }

        $info = is_file($percorso) ? @getimagesize($percorso) : false;

        return $info !== false && max($info[0], $info[1]) > ImageDerivative::LATO_ARCHIVIO;
    }

    /** Scrive la copia ridotta accanto all'originale, aggiorna la scheda e solo dopo elimina il file vecchio. */
    private function sostituisci(Photo $foto, string $ridotta): void
    {
        $disk = Storage::disk();
        $vecchio = $foto->s3_key;
        $nuovo = dirname($vecchio).'/'.Str::uuid7().'.jpg';

        $disk->put($nuovo, $ridotta);
        try {
            DB::transaction(function () use ($foto, $nuovo, $ridotta) {
                $foto->forceFill([
                    's3_key' => $nuovo,
                    'mime_type' => 'image/jpeg',
                    'size_bytes' => strlen($ridotta),
                    'hash_sha256' => hash('sha256', $ridotta),
                ])->save();
            });
        } catch (\Throwable $e) {
            // Il filesystem non partecipa alla transazione: niente file orfani
            $disk->delete($nuovo);
            throw $e;
        }

        $disk->delete($vecchio);
        // La copia ridotta del portale pubblico si rifara' dalla foto nuova
        PublicPhotoCache::dimentica($foto);
    }

    private function mb(int $byte): string
    {
        return number_format($byte / 1048576, 1, ',', '.').' MB';
    }
}
