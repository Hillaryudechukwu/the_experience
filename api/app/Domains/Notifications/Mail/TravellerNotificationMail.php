<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** One outbox row, as a plain email. Not queued: the outbox is the queue. */
class TravellerNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $headline,
        public string $notice,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->headline);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e($this->notice).'</p>');
    }
}
