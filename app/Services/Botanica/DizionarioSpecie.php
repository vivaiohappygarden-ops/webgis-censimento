<?php

namespace App\Services\Botanica;

use App\Models\TreeSpecies;
use App\Support\RicercaTestuale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Il dizionario delle specie: le voci di serie (database/seeders/data/specie.csv,
 * installate dalla migrazione e riallineate a ogni deploy) piu' quelle di ogni
 * organizzazione. Serve alla scheda dell'albero e all'app di campo per
 * compilare da soli genere, famiglia e nome comune scrivendo il nome botanico
 * o quello comune ("pino romano" trova Pinus pinea), e impara le specie nuove
 * che un'organizzazione salva nelle sue schede.
 */
final class DizionarioSpecie
{
    public const CSV = 'database/seeders/data/specie.csv';

    /** Quante voci al massimo porta l'elenco intero (app di campo). */
    public const TETTO_ELENCO = 3000;

    /** Installa le voci di serie dal file e riallinea quelle gia' presenti; torna quante ne ha aggiunte. */
    public static function installaDiSerie(): int
    {
        $percorso = base_path(self::CSV);
        $file = fopen($percorso, 'r');
        if ($file === false) {
            throw new \RuntimeException("File delle specie non leggibile: {$percorso}");
        }
        $intestazione = fgetcsv($file, 0, ';', '"', '\\');
        $aggiunte = 0;
        while (($riga = fgetcsv($file, 0, ';', '"', '\\')) !== false) {
            if (count($riga) < 5 || trim((string) $riga[1]) === '') {
                continue;
            }
            $voce = array_combine($intestazione, array_pad($riga, count($intestazione), null));
            $dati = [
                'genus' => trim((string) $voce['genere']),
                'species' => trim((string) $voce['specie']),
                'cultivar' => trim((string) ($voce['cultivar'] ?? '')) ?: null,
                'family' => trim((string) ($voce['famiglia'] ?? '')) ?: null,
                'common_name' => trim((string) ($voce['nome_comune'] ?? '')) ?: null,
                'synonyms' => array_values(array_filter(array_map('trim', explode('|', (string) ($voce['sinonimi'] ?? ''))))),
                'source' => 'serie',
            ];
            $esistente = self::stessaVoce(null, $dati['species'], $dati['cultivar'])->first();
            if ($esistente) {
                $esistente->fill($dati)->save();
            } else {
                TreeSpecies::create($dati);
                $aggiunte++;
            }
        }
        fclose($file);

        return $aggiunte;
    }

    /** Le voci che valgono per un'organizzazione: le sue piu' quelle di serie. */
    public static function perOrganizzazione(?string $tenantId)
    {
        return TreeSpecies::query()->where(function ($q) use ($tenantId) {
            $q->whereNull('tenant_id');
            if ($tenantId) {
                $q->orWhere('tenant_id', $tenantId);
            }
        });
    }

    /**
     * Ricerca a parole su nomi botanici, comuni, famiglia e sinonimi. Prima le
     * voci dell'organizzazione, poi quelle di serie; le corrispondenze esatte
     * in testa.
     *
     * @return Collection<int, TreeSpecies>
     */
    public static function cerca(?string $tenantId, ?string $testo, int $limite = 12): Collection
    {
        $query = self::perOrganizzazione($tenantId);
        RicercaTestuale::applica($query, $testo, ['search_text']);
        $esatto = mb_strtolower(trim((string) $testo));

        // In testa la voce uguale a quello che si e' scritto (specie, nome comune
        // o uno dei sinonimi: "platano" e' il platano comune), poi quelle in cui
        // il testo e' una parola intera (anche fra i sinonimi: "pino romano"),
        // poi quelle in cui inizia una parola ("platano" prima di "platanoides")
        return $query
            ->orderByRaw(
                "CASE WHEN lower(species) = ? OR lower(coalesce(common_name, '')) = ?"
                ." OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(synonyms) s WHERE lower(s) = ?) THEN 0"
                ." WHEN (' ' || search_text || ' ') LIKE ('% ' || ? || ' %') THEN 1"
                ." WHEN (' ' || search_text) LIKE ('% ' || ? || '%') THEN 2 ELSE 3 END",
                [$esatto, $esatto, $esatto, $esatto, $esatto]
            )
            ->orderByRaw('tenant_id IS NULL')
            ->orderBy('species')->orderBy('cultivar')
            ->limit($limite)
            ->get();
    }

    /** @return list<array<string, mixed>> l'elenco intero, per l'app di campo che lavora senza rete */
    public static function tutte(?string $tenantId): array
    {
        return self::perOrganizzazione($tenantId)->orderBy('species')->orderBy('cultivar')->limit(self::TETTO_ELENCO)->get()
            ->map(fn (TreeSpecies $v) => $v->voce())->all();
    }

    /**
     * Una specie salvata in una scheda che il dizionario non conosce entra fra
     * le voci dell'organizzazione: la prossima volta si trova. Solo nomi
     * binomiali (genere + epiteto) o con cultivar: una parola sola ("aghifoglia")
     * non e' una specie e non si impara. Mai un errore: una scheda si salva
     * anche se il dizionario non riesce a imparare.
     *
     * @param  array<string, mixed>  $albero  i campi della scheda (species, genus, cultivar, family, common_name)
     */
    public static function impara(?string $tenantId, array $albero): void
    {
        $species = trim((string) ($albero['species'] ?? ''));
        $cultivar = trim((string) ($albero['cultivar'] ?? '')) ?: null;
        if (! $tenantId || $species === '' || (! str_contains($species, ' ') && $cultivar === null)) {
            return;
        }
        try {
            if (self::stessaVoce($tenantId, $species, $cultivar, anchePerSerie: true)->exists()) {
                return;
            }
            $genus = trim((string) ($albero['genus'] ?? '')) ?: explode(' ', $species)[0];
            TreeSpecies::create([
                'tenant_id' => $tenantId,
                'genus' => $genus,
                'species' => $species,
                'cultivar' => $cultivar,
                'family' => trim((string) ($albero['family'] ?? '')) ?: null,
                'common_name' => trim((string) ($albero['common_name'] ?? '')) ?: null,
                'synonyms' => [],
                'source' => 'organizzazione',
                'created_by' => Auth::id(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Dizionario delle specie: voce non imparata', ['species' => $species, 'errore' => $e->getMessage()]);
        }
    }

    /** La query della voce con la stessa specie e cultivar (confronto senza maiuscole). */
    public static function stessaVoce(?string $tenantId, string $species, ?string $cultivar, bool $anchePerSerie = false)
    {
        $query = $anchePerSerie ? self::perOrganizzazione($tenantId) : TreeSpecies::query()->where(fn ($q) => $tenantId ? $q->where('tenant_id', $tenantId) : $q->whereNull('tenant_id'));

        return $query
            ->whereRaw('lower(species) = ?', [mb_strtolower($species)])
            ->whereRaw("lower(coalesce(cultivar, '')) = ?", [mb_strtolower((string) $cultivar)]);
    }
}
