<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Mail;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Backup mail transport. SendGrid is tried first; this runs only when that
 * send throws. The API key is the header, never the message.
 */
class BrevoTransport extends AbstractTransport
{
    public function __construct(private readonly string $apiKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->apiKey === '') {
            throw new TransportException('Brevo is not configured.');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $envelope = $message->getEnvelope();
        $sender = $envelope->getSender();

        $payload = [
            'sender' => $this->person($sender),
            'to' => array_map($this->person(...), $this->recipients($email, $envelope)),
            'subject' => (string) $email->getSubject(),
        ];

        /*
         * Carried, not discarded.
         *
         * recipients() strips Cc and Bcc out of `to` so they are not addressed
         * twice, but nothing put them back — so anyone copied on a message
         * received nothing at all, and the send still reported success. This
         * is the failover transport, which by definition runs when the primary
         * is already refusing mail; losing a copy silently there is the worst
         * moment for it.
         */
        foreach (['cc' => $email->getCc(), 'bcc' => $email->getBcc()] as $field => $addresses) {
            if ($addresses !== []) {
                $payload[$field] = array_map($this->person(...), $addresses);
            }
        }

        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        if (is_string($html) && $html !== '') {
            $payload['htmlContent'] = $html;
        }
        if (is_string($text) && $text !== '') {
            $payload['textContent'] = $text;
        }
        if (! isset($payload['htmlContent']) && ! isset($payload['textContent'])) {
            $payload['textContent'] = '';
        }

        $response = Http::withHeaders(['api-key' => $this->apiKey])
            ->acceptJson()
            ->timeout(8)
            ->post('https://api.brevo.com/v3/smtp/email', $payload);

        if ($response->failed()) {
            throw new TransportException('Brevo returned HTTP '.$response->status());
        }

        $messageId = $response->json('messageId');
        if (is_string($messageId) && $messageId !== '') {
            $message->setMessageId($messageId);
        }
    }

    public function __toString(): string
    {
        return 'brevo';
    }

    /** @return array{email: string, name?: string} */
    private function person(Address $address): array
    {
        $person = ['email' => $address->getAddress()];
        if ($address->getName() !== '') {
            $person['name'] = $address->getName();
        }

        return $person;
    }

    /** @return list<Address> */
    private function recipients(Email $email, Envelope $envelope): array
    {
        return array_values(array_filter(
            $envelope->getRecipients(),
            fn (Address $address) => in_array($address, array_merge($email->getCc(), $email->getBcc()), true) === false,
        ));
    }
}
