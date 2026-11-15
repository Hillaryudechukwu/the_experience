<?php

declare(strict_types=1);

namespace App\Domains\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $travellerName,
        public string $resetUrl,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your '.(string) config('app.name').' password');
    }

    public function content(): Content
    {
        $name = (string) config('app.name');
        $greeting = $this->travellerName !== ''
            ? 'Hi '.e($this->travellerName).','
            : 'Hi,';

        $html = implode('', [
            '<div style="font-family:Georgia,serif;font-size:16px;line-height:1.5;color:#1a1a1a;max-width:560px">',
            '<p style="margin:0 0 12px;font-size:22px;font-weight:600">Reset your '.$name.' password</p>',
            '<p style="margin:0 0 16px">'.$greeting.'</p>',
            '<p style="margin:0 0 16px">Someone asked to reset the password on this account. If that was you, open the link below. It expires in one hour.</p>',
            '<p style="margin:0 0 16px"><a href="'.e($this->resetUrl).'" style="color:#2856F6">Choose a new password</a></p>',
            '<p style="margin:0;color:#555">If you did not ask for this, you can ignore the email — your password stays the same.</p>',
            '</div>',
        ]);

        return new Content(htmlString: $html);
    }
}
