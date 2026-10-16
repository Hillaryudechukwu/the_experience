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

    /**
     * Anyone copied on a message used to receive nothing at all.
     *
     * recipients() strips Cc and Bcc out of 'to' so they are not addressed
     * twice, and nothing put them back — so the copy vanished and the send
     * still reported success. This is the failover transport, which runs
     * precisely when the primary is already refusing mail.
     */
    public function test_copied_recipients_are_carried_rather_than_dropped(): void
    {
        Http::fake([
            'https://api.brevo.com/*' => Http::response(['messageId' => '<one@brevo>'], 201),
        ]);

        $email = (new Email)
            ->from('ada@example.com')
            ->to('bea@example.com')
            ->cc('ops@example.com')
            ->bcc('audit@example.com')
            ->subject('Booking confirmed')
            ->text('Confirmed.');

        (new BrevoTransport('xkeysib-test'))->send($email);

        Http::assertSent(function ($request) {
            return $request['to'][0]['email'] === 'bea@example.com'
                && count($request['to']) === 1
                && $request['cc'][0]['email'] === 'ops@example.com'
                && $request['bcc'][0]['email'] === 'audit@example.com';
        });
    }

    /** No empty arrays for a message that simply has no copies. */
    public function test_a_message_without_copies_sends_no_cc_or_bcc_fields(): void
    {
        Http::fake([
            'https://api.brevo.com/*' => Http::response(['messageId' => '<one@brevo>'], 201),
        ]);

        $email = (new Email)
            ->from('ada@example.com')
            ->to('bea@example.com')
            ->subject('Booking confirmed')
            ->text('Confirmed.');

        (new BrevoTransport('xkeysib-test'))->send($email);

        Http::assertSent(fn ($request) => ! isset($request['cc']) && ! isset($request['bcc']));
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
