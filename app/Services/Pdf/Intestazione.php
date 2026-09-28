<?php

namespace App\Services\Pdf;

use App\Models\Organization;
use Illuminate\Support\Facades\Storage;

/**
 * L'intestazione dei documenti stampati, per organizzazione.
 *
 * Un'organizzazione affittata sulla piattaforma e' del cliente in tutto:
 * sui suoi documenti compaiono la sua ragione sociale, i suoi recapiti e il
 * suo logo, mai quelli della piattaforma. I dati stanno in
 * organizations.branding (piu' nome e partita IVA sull'organizzazione) e si
 * regolano dalla pagina Utenti; quello che manca non si stampa.
 */
final class Intestazione
{
    public const CAMPI = ['indirizzo', 'comune', 'telefono', 'email', 'pec', 'sito', 'codice_fiscale'];

    public const CARTELLA = 'intestazioni';

    /**
     * @return array{nome:string, partita_iva:?string, codice_fiscale:?string, indirizzo:?string, comune:?string, telefono:?string, email:?string, pec:?string, sito:?string, logo:?string, righe:array<int,string>}|null
     */
    public static function per(?string $tenantId): ?array
    {
        if (! $tenantId) {
            return null;
        }
        $organizzazione = Organization::query()->find($tenantId);
        if (! $organizzazione) {
            return null;
        }
        $branding = $organizzazione->branding ?? [];
        $valore = fn (string $chiave) => isset($branding[$chiave]) && trim((string) $branding[$chiave]) !== '' ? trim((string) $branding[$chiave]) : null;

        // Righe sotto il nome: sede, contatti, dati fiscali. Solo quello che c'e'.
        $righe = [];
        $sede = array_values(array_filter([$valore('indirizzo'), $valore('comune')]));
        if ($sede) {
            $righe[] = implode(' - ', $sede);
        }
        $contatti = array_values(array_filter([
            $valore('telefono') ? 'tel. '.$valore('telefono') : null,
            $valore('email'),
            $valore('pec') ? 'PEC '.$valore('pec') : null,
            $valore('sito'),
        ]));
        if ($contatti) {
            $righe[] = implode(' - ', $contatti);
        }
        $fiscali = array_values(array_filter([
            $organizzazione->vat_number ? 'P. IVA '.$organizzazione->vat_number : null,
            $valore('codice_fiscale') ? 'C.F. '.$valore('codice_fiscale') : null,
        ]));
        if ($fiscali) {
            $righe[] = implode(' - ', $fiscali);
        }

        return [
            'nome' => $organizzazione->name,
            'partita_iva' => $organizzazione->vat_number,
            'codice_fiscale' => $valore('codice_fiscale'),
            'indirizzo' => $valore('indirizzo'),
            'comune' => $valore('comune'),
            'telefono' => $valore('telefono'),
            'email' => $valore('email'),
            'pec' => $valore('pec'),
            'sito' => $valore('sito'),
            'logo' => self::logoDataUri($branding['logo_path'] ?? null),
            'righe' => $righe,
        ];
    }

    /** Il logo come data URI PNG: dompdf non legge risorse esterne e il file sta nell'archivio privato. */
    public static function logoDataUri(?string $percorso): ?string
    {
        if (! $percorso) {
            return null;
        }
        $disco = Storage::disk();
        if (! $disco->exists($percorso)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) $disco->get($percorso));
    }
}
