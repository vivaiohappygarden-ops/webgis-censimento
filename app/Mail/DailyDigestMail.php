<?php

namespace App\Mail;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/** Il riepilogo mattutino delle scadenze, un'email per destinatario. */
class DailyDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Organization $organization,
        public array $digest,
    ) {}

    public function envelope(): Envelope
    {
        $total = collect($this->digest['sections'])->sum('count');
        $date = Carbon::parse($this->digest['date'])->format('d/m/Y');

        return new Envelope(
            subject: sprintf(
                'Verde %s - %d %s da attenzionare (%s)',
                $this->organization->name,
                $total,
                $total === 1 ? 'scadenza' : 'scadenze',
                $date,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.daily-digest');
    }
}
