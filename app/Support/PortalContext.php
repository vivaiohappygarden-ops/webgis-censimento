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

    /** Vero se il committente ha caricato uno stemma. */
    public function hasLogo(): bool
    {
        return ! empty($this->client->public_profile['logo_path']);
    }
}
