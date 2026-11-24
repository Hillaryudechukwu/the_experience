<?php

declare(strict_types=1);

namespace App\Domains\Ops\Services;

use App\Domains\Ops\Mail\BudgetAlertMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Turns a Cloud Budget Pub/Sub push into an operator email.
 *
 * Google publishes when a configured spend threshold is crossed (e.g. 0.9 =
 * 90% spent). We notify when remaining budget is at or below the configured
 * floor (default 10%), and dedupe so Pub/Sub retries do not fill the inbox.
 */
class CloudBudgetAlertHandler
{
    /**
     * @param  array<string, mixed>  $payload  Decoded Pub/Sub message.data JSON
     * @return 'notified'|'below_threshold'|'no_recipients'|'duplicate'|'malformed'
     */
    public function handle(array $payload): string
    {
        $threshold = $this->spentFraction($payload);

        if ($threshold === null) {
            return 'malformed';
        }

        $remainingFloor = (float) config('experience.ops.budget_remaining_alert_fraction', 0.10);
        $remaining = max(0.0, 1.0 - $threshold);

        if ($remaining > $remainingFloor) {
            return 'below_threshold';
        }

        $recipients = $this->recipients();

        if ($recipients === []) {
            Log::warning('ops.budget_alert.no_recipients', [
                'budget' => (string) ($payload['budgetDisplayName'] ?? ''),
                'remaining' => $remaining,
            ]);

            return 'no_recipients';
        }

        $dedupeKey = $this->dedupeKey($payload, $threshold);

        /* Claim the slot first so concurrent Pub/Sub retries do not double-send,
           but release it if queueing the mail fails so a later push can retry. */
        if (! Cache::add($dedupeKey, true, now()->addDay())) {
            return 'duplicate';
        }

        $alert = [
            'budget' => (string) ($payload['budgetDisplayName'] ?? 'Unnamed budget'),
            'remaining_pct' => round($remaining * 100, 1),
            'spent_pct' => round($threshold * 100, 1),
            'cost' => isset($payload['costAmount']) ? (string) $payload['costAmount'] : null,
            'amount' => isset($payload['budgetAmount']) ? (string) $payload['budgetAmount'] : null,
            'currency' => (string) ($payload['currencyCode'] ?? 'GBP'),
        ];

        try {
            Mail::to($recipients)->send(new BudgetAlertMail($alert));
        } catch (\Throwable $e) {
            Cache::forget($dedupeKey);

            throw $e;
        }

        Cache::put('ops:budget_alert:last', [
            ...$alert,
            'notified_at' => now()->toIso8601String(),
        ], now()->addDays(7));

        Log::info('ops.budget_alert.notified', [
            'budget' => $alert['budget'],
            'remaining_pct' => $alert['remaining_pct'],
            'recipients' => count($recipients),
        ]);

        return 'notified';
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            (array) config('experience.ops.alert_emails', []),
        )));
    }

    /** @return array<string, mixed>|null */
    public function lastAlert(): ?array
    {
        $last = Cache::get('ops:budget_alert:last');

        return is_array($last) ? $last : null;
    }

    /** @param  array<string, mixed>  $payload */
    private function spentFraction(array $payload): ?float
    {
        if (! isset($payload['alertThresholdExceeded'])) {
            return null;
        }

        $value = $payload['alertThresholdExceeded'];

        if (! is_numeric($value)) {
            return null;
        }

        $fraction = (float) $value;

        return $fraction >= 0.0 && $fraction <= 1.0 ? $fraction : null;
    }

    /** @param  array<string, mixed>  $payload */
    private function dedupeKey(array $payload, float $threshold): string
    {
        $budget = (string) ($payload['budgetDisplayName'] ?? 'budget');
        $interval = (string) ($payload['costIntervalStart'] ?? now()->toDateString());
        $bucket = (string) round($threshold, 2);

        return 'ops:budget_alert:'.sha1($budget.'|'.$interval.'|'.$bucket);
    }
}
