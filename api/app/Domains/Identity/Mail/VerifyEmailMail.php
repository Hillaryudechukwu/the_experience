<?php

declare(strict_types=1);

namespace App\Domains\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyEmailMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $travellerName,
        public string $verifyUrl,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your '.(string) config('app.name').' email');
    }

    public function content(): Content
    {
        $name = (string) config('app.name');
        $greeting = $this->travellerName !== ''
            ? 'Hi '.e($this->travellerName).','
            : 'Hi,';

        $html = implode('', [
            '<div style="font-family:Georgia,serif;font-size:16px;line-height:1.5;color:#1a1a1a;max-width:560px">',
            '<p style="margin:0 0 12px;font-size:22px;font-weight:600">Confirm your email</p>',
            '<p style="margin:0 0 16px">'.$greeting.'</p>',
            '<p style="margin:0 0 16px">Confirm this address so we can reach you about your '.$name.' account — password resets and subscription receipts go here.</p>',
            '<p style="margin:0 0 16px"><a href="'.e($this->verifyUrl).'" style="color:#2856F6">Confirm email</a></p>',
            '<p style="margin:0;color:#555">If you did not create a '.$name.' account, you can ignore this.</p>',
            '</div>',
        ]);

        return new Content(htmlString: $html);
    }
}
