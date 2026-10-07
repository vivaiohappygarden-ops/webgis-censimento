<?php

namespace App\Mail;

use App\Models\Asset;
use App\Models\ClientAlert;
use App\Models\Organization;
use App\Models\TreeAssessment;
use App\Services\Pdf\Intestazione;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * L'email dell'avviso al committente: l'albero, la classe, la richiesta di
 * chiudere l'area, le prescrizioni e come prendere atto. Una per
 * destinatario; a chi avvisa arriva la copia con l'elenco degli indirizzi.
 */
class AvvisoCommittenteMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{email: string, nome: ?string, origine: string}  $destinatario
     * @param  list<array<string, mixed>>  $esiti  solo nella copia a chi avvisa
     */
    public function __construct(
        public ?Organization $organization,
        public ClientAlert $avviso,
        public Asset $asset,
        public TreeAssessment $assessment,
        public array $destinatario,
        public array $esiti = [],
    ) {}

    public function envelope(): Envelope
    {
        $cartellino = $this->asset->census_code ?: 'albero senza cartellino';
        $area = $this->avviso->area?->name;

        return new Envelope(
            // L'indirizzo e' quello del server, il nome quello dell'organizzazione
            from: new Address((string) config('mail.from.address'), $this->organization?->name ?? (string) config('mail.from.name')),
            subject: ($this->destinatario['origine'] === 'copia' ? 'Copia - ' : '')
                .'Avviso urgente: '.$cartellino.($area ? ', '.$area : '').' - area da chiudere o interdire',
        );
    }

    public function content(): Content
    {
        $this->asset->loadMissing('tree', 'area:id,name');

        return new Content(view: 'emails.avviso-committente', with: [
            'intestazione' => Intestazione::per($this->asset->tenant_id),
            'portale' => rtrim((string) config('app.url'), '/').'/portale',
        ]);
    }
}
