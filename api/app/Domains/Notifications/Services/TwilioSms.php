<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends one SMS through Twilio's Messages API.
 *
 * Travellers do not have a phone number on the account, so the only
 * recipients are the operator numbers in TWILIO_ALERT_RECIPIENTS.
 * A missing or mistyped credential skips the channel rather than failing
 * the email and push that already went out.
 */
class TwilioSms
{
    public function configured(): bool
    {
        return $this->accountSid() !== null
            && $this->authToken() !== null
            && ($this->messagingServiceSid() !== null || $this->from() !== null);
    }

    /** @return list<string> */
    public function recipients(): array
    {
        $numbers = config('services.twilio.alert_recipients', []);

        return array_values(array_filter(
            is_array($numbers) ? $numbers : [],
            fn ($number) => is_string($number) && preg_match('/^\+[1-9]\d{7,14}$/', $number) === 1,
        ));
    }

    public function send(string $to, string $body): void
    {
        $sid = $this->accountSid();
        $token = $this->authToken();
        if ($sid === null || $token === null) {
            throw new RuntimeException('Twilio is not configured.');
        }

        $payload = [
            'To' => $to,
            'Body' => mb_substr($body, 0, 1000),
        ];

        $service = $this->messagingServiceSid();
        if ($service !== null) {
            $payload['MessagingServiceSid'] = $service;
        } else {
            $payload['From'] = $this->from();
        }

        $response = Http::withBasicAuth($sid, $token)
            ->asForm()
            ->timeout(8)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", $payload);

        if ($response->failed()) {
            throw new RuntimeException('Twilio SMS returned HTTP '.$response->status());
        }
    }

    private function accountSid(): ?string
    {
        return $this->prefixed('services.twilio.account_sid', 'AC');
    }

    private function messagingServiceSid(): ?string
    {
        return $this->prefixed('services.twilio.messaging_service_sid', 'MG');
    }

    private function authToken(): ?string
    {
        $token = config('services.twilio.auth_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function from(): ?string
    {
        $from = config('services.twilio.from');

        return is_string($from) && preg_match('/^\+[1-9]\d{7,14}$/', $from) === 1 ? $from : null;
    }

    private function prefixed(string $key, string $prefix): ?string
    {
        $value = config($key);

        return is_string($value) && str_starts_with($value, $prefix) ? $value : null;
    }
}
