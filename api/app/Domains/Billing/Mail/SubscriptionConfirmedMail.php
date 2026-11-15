<?php

declare(strict_types=1);

namespace App\Domains\Billing\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Receipt for a month that just opened (purchase or restore that entitled).
 *
 * Queued, so the refresh response is not held open for Brevo. Not
 * afterCommit: the notifier queues it inside the transaction that marks the
 * period as confirmed, so the mark and the job commit or roll back together.
 *
 * No price: the store charged in the traveller's own currency, and a fixed
 * label here would disagree with the receipt the store already sent.
 */
class SubscriptionConfirmedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $travellerName,
        public string $storeLabel,
        public string $paidUntilLabel,
    ) {}

    public function envelope(): Envelope
    {
        $name = (string) config('app.name');

        return new Envelope(subject: "Your {$name} month is active");
    }

    public function content(): Content
    {
        $name = (string) config('app.name');
        $greeting = $this->travellerName !== ''
            ? 'Hi '.e($this->travellerName).','
            : 'Hi,';

        $html = implode('', [
            '<div style="font-family:Georgia,serif;font-size:16px;line-height:1.5;color:#1a1a1a;max-width:560px">',
            '<p style="margin:0 0 12px;font-size:22px;font-weight:600">Your '.$name.' month is active</p>',
            '<p style="margin:0 0 16px">'.$greeting.'</p>',
            '<p style="margin:0 0 16px">Thanks — '.$name.' is open on this account until <strong>'.e($this->paidUntilLabel).'</strong>.</p>',
            '<p style="margin:0 0 16px">Billed monthly by '.e($this->storeLabel).'. Renews until you pause or cancel in your '.e($this->storeLabel).' account. The month you have paid for always runs to its end.</p>',
            '<p style="margin:0;color:#555">Manage or cancel in '.e($this->storeLabel).'. Deleting your '.$name.' account does not cancel the store subscription.</p>',
            '</div>',
        ]);

        return new Content(htmlString: $html);
    }
}
