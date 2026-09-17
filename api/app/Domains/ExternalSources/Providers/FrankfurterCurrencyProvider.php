<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Providers;

use App\Domains\ExternalSources\Contracts\CurrencyProvider;
use App\Domains\ExternalSources\Services\OutboundHttp;
use Illuminate\Support\Facades\Cache;

/**
 * Reference exchange rates (European Central Bank data via Frankfurter).
 *
 * Used to show a traveller their budget in the currency they think in while
 * prices stay in the currency they will actually pay. Rates are daily
 * reference rates, not the rate a card issuer will give, and the API says so
 * alongside the number.
 */
class FrankfurterCurrencyProvider implements CurrencyProvider
{
    public function __construct(private readonly OutboundHttp $http) {}

    public function key(): string
    {
        return 'frankfurter';
    }

    public function rate(string $from, string $to): ?array
    {
        $from = mb_strtoupper($from);
        $to = mb_strtoupper($to);

        if ($from === $to) {
            return ['rate' => 1.0, 'as_of' => now()->toDateString(), 'source' => $this->key()];
        }

        return Cache::remember(
            "fx:{$from}:{$to}",
            (int) config('experience.currency.cache_seconds', 21600),
            function () use ($from, $to) {
                $response = $this->http
                    ->for($this->key(), 8)
                    ->get(rtrim((string) config('experience.currency.base_url'), '/') . '/v1/latest', [
                        'base' => $from,
                        'symbols' => $to,
                    ]);

                if ($response->failed() || $response->json("rates.{$to}") === null) {
                    return null;
                }

                return [
                    'rate' => (float) $response->json("rates.{$to}"),
                    'as_of' => (string) $response->json('date'),
                    'source' => $this->key(),
                ];
            },
        );
    }
}
