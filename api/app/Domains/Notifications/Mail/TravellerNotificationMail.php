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

    /**
     * @param  array{reference?: string, cta_label?: string, cta_hint?: string}  $details
     */
    public function __construct(
        public string $headline,
        public string $notice,
        public array $details = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->headline);
    }

    public function content(): Content
    {
        $parts = [
            '<div style="font-family:Georgia,serif;font-size:16px;line-height:1.5;color:#1a1a1a;max-width:560px">',
            '<p style="margin:0 0 12px;font-size:22px;font-weight:600">'.e($this->headline).'</p>',
            '<p style="margin:0 0 16px">'.e($this->notice).'</p>',
        ];

        if (($this->details['reference'] ?? '') !== '') {
            $parts[] = '<p style="margin:0 0 16px"><strong>Reference:</strong> '.e((string) $this->details['reference']).'</p>';
        }

        if (($this->details['cta_hint'] ?? '') !== '') {
            $parts[] = '<p style="margin:0;color:#555">'.e((string) $this->details['cta_hint']).'</p>';
        } elseif (($this->details['cta_label'] ?? '') !== '') {
            $parts[] = '<p style="margin:0;color:#555">'.e((string) $this->details['cta_label']).'</p>';
        }

        $parts[] = '</div>';

        return new Content(htmlString: implode('', $parts));
    }
}
