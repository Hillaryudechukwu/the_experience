<?php

declare(strict_types=1);

namespace App\Domains\Ops\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Operator alert when a Google Cloud budget is nearly spent.
 *
 * Queued so the Pub/Sub push returns quickly. Cloud Budget will retry on
 * non-2xx; holding the push open for Brevo would turn a slow mailbox into
 * duplicate alerts.
 */
class BudgetAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array{budget: string, remaining_pct: float, spent_pct: float, cost: ?string, amount: ?string, currency: string}  $alert
     */
    public function __construct(public array $alert) {}

    public function envelope(): Envelope
    {
        $name = (string) config('app.name');
        $remaining = number_format($this->alert['remaining_pct'], 1);

        return new Envelope(subject: "{$name}: cloud budget {$remaining}% remaining — {$this->alert['budget']}");
    }

    public function content(): Content
    {
        $name = (string) config('app.name');
        $budget = e($this->alert['budget']);
        $remaining = number_format($this->alert['remaining_pct'], 1);
        $spent = number_format($this->alert['spent_pct'], 1);
        $currency = e($this->alert['currency']);
        $cost = $this->alert['cost'] !== null ? e($this->alert['cost']) : 'unknown';
        $amount = $this->alert['amount'] !== null ? e($this->alert['amount']) : 'unknown';

        $html = implode('', [
            '<div style="font-family:Georgia,serif;font-size:16px;line-height:1.5;color:#1a1a1a;max-width:560px">',
            '<p style="margin:0 0 12px;font-size:22px;font-weight:600">Cloud budget running low</p>',
            '<p style="margin:0 0 16px"><strong>'.$budget.'</strong> for '.$name.' has about <strong>'.$remaining.'%</strong> left ('.$spent.'% spent).</p>',
            '<p style="margin:0 0 16px">Spend: '.$cost.' '.$currency.' of '.$amount.' '.$currency.'.</p>',
            '<p style="margin:0 0 16px">Check Google Cloud Billing, and if Places spend is climbing with destination activation, consider lowering <code>DESTINATION_ACTIVATION_ROLLOUT_PERCENTAGE</code> or setting <code>DESTINATION_ACTIVATION_ENABLED=false</code>.</p>',
            '<p style="margin:0;color:#555">This alert fires at or below the configured remaining threshold (default 10%).</p>',
            '</div>',
        ]);

        return new Content(htmlString: $html);
    }
}
