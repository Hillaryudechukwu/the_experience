<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Notifications\Mail\BrevoTransport;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class BrevoTransportTest extends TestCase
{
    public function test_it_posts_the_message_to_brevo(): void
    {
        Http::fake([
            'https://api.brevo.com/*' => Http::response(['messageId' => '<one@brevo>'], 201),
        ]);

        $email = (new Email)
            ->from(new Address('ada@example.com', 'Ada'))
            ->to('bea@example.com')
            ->subject('Booking confirmed')
            ->html('<p>Confirmed.</p>');

        (new BrevoTransport('xkeysib-test'))->send($email);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request->hasHeader('api-key', 'xkeysib-test')
                && $request['sender']['email'] === 'ada@example.com'
                && $request['sender']['name'] === 'Ada'
                && $request['to'][0]['email'] === 'bea@example.com'
                && $request['subject'] === 'Booking confirmed'
                && $request['htmlContent'] === '<p>Confirmed.</p>';
        });
    }

    public function test_a_rejected_message_throws_so_the_backup_can_fail_closed(): void
    {
        Http::fake([
            'https://api.brevo.com/*' => Http::response(['message' => 'no'], 401),
        ]);

        $email = (new Email)
            ->from('ada@example.com')
            ->to('bea@example.com')
            ->subject('Booking confirmed')
            ->text('Confirmed.');

        $this->expectException(TransportException::class);

        (new BrevoTransport('xkeysib-test'))->send($email);
    }
}
