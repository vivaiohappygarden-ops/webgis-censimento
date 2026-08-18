<?php

namespace App\Support;

use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Numerazione delle etichette per committente.
 *
 * Ogni committente ha un prefisso di due-sei caratteri e una numerazione che
 * riparte da uno: MEN-0001, GUI-0001. Il codice viene assegnato dal server,
 * mai dal dispositivo, perché due operatori che censiscono nello stesso
 * momento (anche rientrando da offline) non devono ottenere lo stesso numero.
 */
class PortalLabels
{
    /** Committente a cui appartiene un'area, risalendo località e sede. */
    public static function clientOfArea(string $areaId): ?Client
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT s.client_id
            FROM areas a
            JOIN localities l ON l.id = a.locality_id
            JOIN sites s ON s.id = l.site_id
            WHERE a.id = ?
            SQL, [$areaId]);

        if ($row?->client_id === null) {
            return null;
        }

        return Client::withoutGlobalScopes()->find($row->client_id);
    }

    /**
     * Prossimo codice per il committente dell'area, oppure null se il
     * committente non ha un prefisso configurato (allora il codice resta
     * quello scritto a mano, come prima).
     *
     * Va chiamata dentro una transazione: il lock è di transazione.
     */
    public static function nextCode(string $areaId): ?string
    {
        $client = self::clientOfArea($areaId);

        if ($client === null || $client->label_prefix === null) {
            return null;
        }

        return self::nextCodeForClient($client);
    }

    public static function nextCodeForClient(Client $client): string
    {
        $prefisso = $client->label_prefix;

        // Il lock è per committente: due Comuni diversi possono numerare
        // in parallelo senza aspettarsi a vicenda
        DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 42))', ["etichetta:{$client->id}"]);

        // Massimo già presente fra i codici che seguono lo schema del prefisso:
        // tiene conto anche di quelli importati o scritti a mano
        $daRighe = (int) DB::selectOne(
            "SELECT COALESCE(MAX((substring(census_code FROM '[0-9]+$'))::int), 0) AS n
             FROM assets
             WHERE tenant_id = ? AND deleted_at IS NULL AND census_code ~ ?",
            [$client->tenant_id, '^'.$prefisso.'-[0-9]+$'],
        )->n;

        // Contatore che non torna mai indietro: se un elemento viene
        // eliminato il suo numero non va riassegnato, perché il cartellino
        // fisico potrebbe essere già stampato e applicato in campo
        $bloccato = Client::withoutGlobalScopes()->lockForUpdate()->findOrFail($client->id);
        $prossimo = max($daRighe, (int) $bloccato->label_counter) + 1;

        $bloccato->forceFill(['label_counter' => $prossimo])->save();

        return sprintf('%s-%04d', $prefisso, $prossimo);
    }

    /** Prefisso proposto dal nome del committente: "Comune di Mentana" -> "MEN". */
    public static function suggestPrefix(string $name): string
    {
        $pulito = Str::of($name)->ascii()->upper()->replaceMatches('/[^A-Z0-9 ]/', ' ')->squish();

        $ignorate = ['COMUNE', 'DI', 'DEL', 'DELLA', 'DELLE', 'DEI', 'DEGLI', 'DA', 'IL', 'LA',
            'LO', 'LE', 'I', 'GLI', 'CITTA', 'CONDOMINIO', 'SPA', 'SRL', 'SNC', 'SAS'];

        $parole = collect(explode(' ', (string) $pulito))
            ->filter(fn ($p) => $p !== '' && ! in_array($p, $ignorate, true))
            ->values();

        if ($parole->isEmpty()) {
            $parole = collect([(string) $pulito]);
        }

        $base = $parole->count() >= 3
            ? $parole->take(3)->map(fn ($p) => substr($p, 0, 1))->implode('')
            : substr($parole->first(), 0, 3);

        $base = substr(preg_replace('/[^A-Z0-9]/', '', $base) ?: 'CMT', 0, 6);

        return strlen($base) >= 2 ? $base : str_pad($base, 2, 'X');
    }

    /** Prefisso proposto e già reso univoco dentro l'impresa. */
    public static function uniquePrefix(string $tenantId, string $name, ?string $ignoreId = null): string
    {
        $base = self::suggestPrefix($name);

        for ($i = 0; $i < 100; $i++) {
            $candidato = $i === 0 ? $base : substr($base, 0, 5).$i;

            $preso = Client::withoutGlobalScopes()->whereNull('deleted_at')
                ->where('tenant_id', $tenantId)
                ->where('label_prefix', $candidato)
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists();

            if (! $preso) {
                return $candidato;
            }
        }

        return $base.random_int(100, 999);
    }

    /** Slug proposto e già reso univoco su tutto l'archivio. */
    public static function uniqueSlug(string $name, ?string $ignoreId = null): string
    {
        $base = self::suggestSlug($name) ?: 'comune';

        for ($i = 0; $i < 100; $i++) {
            $candidato = $i === 0 ? $base : $base.'-'.($i + 1);

            $preso = Client::withoutGlobalScopes()->whereNull('deleted_at')
                ->where('public_slug', $candidato)
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists();

            if (! $preso && ! in_array($candidato, config('portal.reserved_slugs', []), true)) {
                return $candidato;
            }
        }

        return $base.'-'.random_int(100, 999);
    }

    /** Slug proposto per il sottodominio: "Comune di Sant'Angelo" -> "sant-angelo". */
    public static function suggestSlug(string $name): string
    {
        $pulito = Str::of($name)->ascii()->lower()
            ->replaceMatches('/\b(comune|citta) (di|del|della|delle|dei|degli)\b/', ' ')
            // L'apostrofo separa le parole: "sant'angelo" -> "sant-angelo",
            // non "santangelo"
            ->replaceMatches("/['’]/", ' ');

        $slug = Str::slug((string) $pulito);
        $slug = $slug !== '' ? $slug : Str::slug($name);

        // Il troncamento non deve lasciare un trattino in coda: un
        // sottodominio non può iniziare o finire con il trattino
        return trim(substr($slug, 0, 40), '-');
    }
}
