<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Notifications\Mail\TravellerNotificationMail;
use App\Domains\Notifications\Models\DevicePushToken;
use App\Domains\Notifications\Models\NotificationOutbox;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Claims due outbox rows and delivers them.
 *
 * Email is for an account. Push is for any device that registered a token.
 * SMS goes to the operator numbers in TWILIO_ALERT_RECIPIENTS when Twilio
 * is configured. A guest with none of these still has the row, which is the
 * in-app notice. A channel that already succeeded is not tried again, so a
 * retry after a failed push does not send the email a second time.
 */
class NotificationDispatcher
{
    private const BACKOFF_MINUTES = [1, 5, 15, 60];

    public function flush(int $limit = 25): int
    {
        if (! config('experience.notifications.enabled', true)) {
            return 0;
        }

        $this->releaseStuckClaims();

        $sent = 0;

        for ($i = 0; $i < $limit; $i++) {
            $row = $this->claim();
            if ($row === null) {
                break;
            }

            try {
                $this->deliver($row);
                $sent++;
            } catch (Throwable $e) {
                $this->retry($row, $e);
            }
        }

        return $sent;
    }

    private function releaseStuckClaims(): void
    {
        NotificationOutbox::query()
            ->where('status', 'sending')
            ->where('updated_at', '<', CarbonImmutable::now()->subMinutes(10))
            ->update(['status' => 'scheduled']);
    }

    private function claim(): ?NotificationOutbox
    {
        return NotificationOutbox::query()->getConnection()->transaction(function () {
            $row = NotificationOutbox::query()
                ->where('status', 'scheduled')
                ->where('scheduled_for', '<=', CarbonImmutable::now())
                ->orderBy('scheduled_for')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return null;
            }

            $row->status = 'sending';
            $row->save();

            return $row;
        });
    }

    private function deliver(NotificationOutbox $row): void
    {
        $payload = $row->payload ?? [];
        $sent = array_values(array_filter((array) ($payload['sent_channels'] ?? [])));

        if ($row->user_id !== null && ! in_array('email', $sent, true)) {
            $user = User::query()->find($row->user_id);
            if ($user !== null && $user->email !== null && $user->email !== '') {
                Mail::to($user->email)->send(new TravellerNotificationMail($row->title, $row->body));
                $sent[] = 'email';
                $this->rememberChannels($row, $sent);
            }
        }

        if (! in_array('push', $sent, true)) {
            $tokens = $this->tokens($row);
            if ($tokens !== []) {
                $this->push($row, $tokens);
                $sent[] = 'push';
                $this->rememberChannels($row, $sent);
            }
        }

        if (! in_array('sms', $sent, true) && $this->sms($row)) {
            $sent[] = 'sms';
            $this->rememberChannels($row, $sent);
        }

        $row->status = 'sent';
        $row->sent_at = CarbonImmutable::now();
        $row->last_error = null;
        if ($sent === []) {
            $payload['channel'] = 'in_app';
            $row->payload = $payload;
        }
        $row->save();
    }

    /** @param list<string> $sent */
    private function rememberChannels(NotificationOutbox $row, array $sent): void
    {
        $payload = $row->payload ?? [];
        $payload['sent_channels'] = $sent;
        $row->payload = $payload;
        $row->save();
    }

    /** @return list<string> */
    private function tokens(NotificationOutbox $row): array
    {
        $query = DevicePushToken::query();

        if ($row->user_id !== null) {
            $query->where('user_id', $row->user_id);
        } elseif ($row->guest_session_id !== null) {
            $query->where('guest_session_id', $row->guest_session_id);
        } else {
            return [];
        }

        return $query->pluck('token')->all();
    }

    /**
     * Texts the operator list. Returns false when Twilio is not ready, so
     * email and push can still complete.
     */
    private function sms(NotificationOutbox $row): bool
    {
        $sms = app(TwilioSms::class);
        $recipients = $sms->recipients();
        if (! $sms->configured() || $recipients === []) {
            return false;
        }

        $payload = $row->payload ?? [];
        $already = array_values(array_filter((array) ($payload['sent_sms'] ?? [])));
        $text = $row->title.'. '.$row->body;

        foreach ($recipients as $to) {
            if (in_array($to, $already, true)) {
                continue;
            }

            $sms->send($to, $text);
            $already[] = $to;
            $payload['sent_sms'] = $already;
            $row->payload = $payload;
            $row->save();
        }

        return true;
    }

    /** @param list<string> $tokens */
    private function push(NotificationOutbox $row, array $tokens): void
    {
        $response = Http::timeout(8)
            ->acceptJson()
            ->post('https://exp.host/--/api/v2/push/send', [
                'to' => $tokens,
                'title' => $row->title,
                'body' => $row->body,
                'data' => [
                    'kind' => $row->kind,
                    'notification_id' => $row->id,
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Expo push returned HTTP '.$response->status());
        }

        foreach ((array) $response->json('data') as $ticket) {
            if (is_array($ticket) && ($ticket['status'] ?? null) === 'error') {
                throw new \RuntimeException((string) ($ticket['message'] ?? 'Expo push ticket error'));
            }
        }
    }

    private function retry(NotificationOutbox $row, Throwable $e): void
    {
        $attempts = $row->attempts + 1;
        $max = (int) config('experience.notifications.max_attempts', 5);
        $message = mb_substr($e->getMessage(), 0, 500);

        if ($attempts >= $max) {
            $row->update([
                'status' => 'failed',
                'attempts' => $attempts,
                'last_error' => $message,
            ]);
            Log::warning('notification.failed', ['id' => $row->id, 'kind' => $row->kind, 'message' => $message]);

            return;
        }

        $minutes = self::BACKOFF_MINUTES[min($attempts - 1, count(self::BACKOFF_MINUTES) - 1)];

        $row->update([
            'status' => 'scheduled',
            'attempts' => $attempts,
            'last_error' => $message,
            'scheduled_for' => CarbonImmutable::now()->addMinutes($minutes),
        ]);

        Log::warning('notification.retry', ['id' => $row->id, 'kind' => $row->kind, 'attempts' => $attempts]);
    }
}
