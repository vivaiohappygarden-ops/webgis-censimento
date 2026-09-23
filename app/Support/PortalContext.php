<?php

namespace App\Support;

use App\Models\Client;

/**
 * Contesto del portale pubblico per la richiesta in corso: quale committente
 * stiamo servendo e da quale indirizzo.
 *
 * Il portale è raggiungibile in due modi che devono restare intercambiabili:
 * dal sottodominio (mentana.<dominio>) e dal percorso di ripiego
 * (/comune/mentana), usato in collaudo e finché il DNS non è pronto. Tutti i
 * collegamenti interni passano da qui, così le pagine non sanno quale dei due
 * è in uso.
 */
class PortalContext
{
    public function __construct(
        public readonly Client $client,
        public readonly string $basePath = '',
    ) {}

    /** Indirizzo interno al portale: url('/mappa') -> /comune/mentana/mappa. */
    public function url(string $path = '/'): string
    {
        $path = '/'.ltrim($path, '/');

        return rtrim($this->basePath.($path === '/' ? '' : $path), '/') ?: '/';
    }

    public function name(): string
    {
        return $this->client->publicName();
    }

    /** Colore dell'intestazione scelto dal committente. */
    public function color(): string
    {
        $colore = (string) ($this->client->public_profile['color'] ?? '');

        return preg_match('/^#[0-9a-fA-F]{6}$/', $colore) ? $colore : '#14532d';
    }

    public function welcomeText(): string
    {
        return trim((string) ($this->client->public_profile['welcome_text'] ?? ''));
    }

    public function contactEmail(): ?string
    {
        $mail = trim((string) ($this->client->public_profile['contact_email'] ?? ''));

        return filter_var($mail, FILTER_VALIDATE_EMAIL) ? $mail : null;
    }

    public function footerText(): string
    {
        return trim((string) ($this->client->public_profile['footer_text'] ?? ''));
    }

    /**
     * Vero se il committente ha scelto di pubblicare la stima dell'anidride
     * carbonica. Spento di proposito finché il tecnico non ha verificato il
     * modello di calcolo: è un numero che si può contestare.
     */
    public function mostraCo2(): bool
    {
        return (bool) ($this->client->public_profile['show_co2'] ?? false);
    }

    /**
     * Vero se il committente ha scelto di pubblicare anche gli altri benefici
     * ambientali (ossigeno, polveri sottili, pioggia intercettata).
     *
     * Interruttore separato da quello della CO2, e spento di suo: sono
     * stime nuove, con coefficienti che dipendono dal clima e dall'aria del
     * posto. Niente esce in pubblico da solo, nemmeno appoggiandosi a un
     * consenso dato mesi fa per un altro numero.
     */
    public function mostraBenefici(): bool
    {
        return (bool) ($this->client->public_profile['show_benefici'] ?? false);
    }

    /** Vero se il committente ha caricato uno stemma. */
    public function hasLogo(): bool
    {
        return ! empty($this->client->public_profile['logo_path']);
    }

    /**
     * Vero se il Comune ha caricato la fotografia di copertina della home
     * (veste mista del 23/09/2026). Senza fotografia l'apertura resta su
     * fondo colorato: non si mette un'immagine di riempimento al suo posto.
     */
    public function hasCover(): bool
    {
        return ! empty($this->client->public_profile['cover_path']);
    }

    /**
     * Recapiti dell'ufficio per il pie' di pagina. Ogni voce esce solo se
     * l'ente l'ha compilata: niente etichette vuote, niente trattini.
     */
    public function address(): ?string
    {
        return $this->testo('address');
    }

    public function openingHours(): ?string
    {
        return $this->testo('opening_hours');
    }

    public function contactPhone(): ?string
    {
        return $this->testo('contact_phone');
    }

    /** Il numero ridotto a cifre e prefisso, come lo vuole un collegamento tel: */
    public function contactPhoneHref(): ?string
    {
        $numero = $this->contactPhone();

        return $numero === null ? null : 'tel:'.preg_replace('/[^0-9+]/', '', $numero);
    }

    public function contactPec(): ?string
    {
        $pec = trim((string) ($this->client->public_profile['contact_pec'] ?? ''));

        return filter_var($pec, FILTER_VALIDATE_EMAIL) ? $pec : null;
    }

    public function accessibilityUrl(): ?string
    {
        return filter_var($this->client->public_profile['accessibility_url'] ?? '', FILTER_VALIDATE_URL) ?: null;
    }

    private function testo(string $chiave): ?string
    {
        $valore = trim((string) ($this->client->public_profile[$chiave] ?? ''));

        return $valore === '' ? null : $valore;
    }
}
