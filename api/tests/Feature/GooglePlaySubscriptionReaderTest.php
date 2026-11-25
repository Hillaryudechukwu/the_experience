<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Services\GooglePlaySubscriptionReader;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GooglePlaySubscriptionReaderTest extends TestCase
{
    public function test_returns_null_when_credentials_are_missing(): void
    {
        config([
            'experience.billing.google.package_name' => 'uk.co.synteric.experience',
            'experience.billing.google.service_account_json' => '',
        ]);

        $reader = app(GooglePlaySubscriptionReader::class);

        $this->assertNull($reader->read('token'));
        $this->assertSame(GooglePlaySubscriptionReader::FAILURE_UNCONFIGURED, $reader->lastFailure());
    }

    /**
     * @return array<string, array{0: int|null, 1: int|null, 2: string}>
     */
    public static function failureCases(): array
    {
        return [
            'token gone on both reads' => [410, 404, GooglePlaySubscriptionReader::FAILURE_INVALID_TOKEN],
            'permission denied' => [403, 403, GooglePlaySubscriptionReader::FAILURE_UNCONFIGURED],
            'play outage on v2 beats dead v1' => [503, 404, GooglePlaySubscriptionReader::FAILURE_TRANSIENT],
            'connection failure' => [null, null, GooglePlaySubscriptionReader::FAILURE_TRANSIENT],
        ];
    }

    #[DataProvider('failureCases')]
    public function test_classifies_why_a_read_failed(?int $v2Status, ?int $v1Status, string $expected): void
    {
        $this->configureServiceAccount();

        $respond = fn (?int $status) => $status === null
            ? fn () => throw new ConnectionException('timed out')
            : Http::response(['error' => ['message' => 'nope']], $status);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'https://androidpublisher.googleapis.com/*/subscriptionsv2/*' => $respond($v2Status),
            'https://androidpublisher.googleapis.com/*/subscriptions/*' => $respond($v1Status),
        ]);

        $reader = app(GooglePlaySubscriptionReader::class);

        $this->assertNull($reader->read('purchase-token', 'wayoa_monthly'));
        $this->assertSame($expected, $reader->lastFailure());
    }

    private function configureServiceAccount(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($key, $pem);

        config([
            'experience.billing.google.package_name' => 'uk.co.synteric.experience',
            'experience.billing.google.service_account_json' => json_encode([
                'client_email' => 'play-failures@example.iam.gserviceaccount.com',
                'private_key' => $pem,
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    public function test_reads_subscriptionsv2_expiry_when_play_answers(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($key, $pem);

        config([
            'experience.billing.google.package_name' => 'uk.co.synteric.experience',
            'experience.billing.google.service_account_json' => json_encode([
                'client_email' => 'play@example.iam.gserviceaccount.com',
                'private_key' => $pem,
            ], JSON_THROW_ON_ERROR),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.test',
                'expires_in' => 3600,
            ]),
            'https://androidpublisher.googleapis.com/*' => Http::response([
                'subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE',
                'lineItems' => [[
                    'productId' => 'wayoa_monthly',
                    'expiryTime' => '2026-12-01T00:00:00Z',
                    'autoRenewingPlan' => new \stdClass,
                ]],
            ]),
        ]);

        $detail = app(GooglePlaySubscriptionReader::class)->read('purchase-token');

        $this->assertNotNull($detail);
        $this->assertSame('wayoa_monthly', $detail['productId']);
        $this->assertSame(
            (int) (new \DateTimeImmutable('2026-12-01T00:00:00Z'))->format('Uv'),
            $detail['expiryTimeMillis'],
        );
        $this->assertTrue($detail['autoRenewing']);
    }

    public function test_reads_when_private_key_newlines_were_double_escaped_in_env(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($key, $pem);

        /* Mimic a .env value where PEM newlines survived json_decode as "\n". */
        config([
            'experience.billing.google.package_name' => 'uk.co.synteric.experience',
            'experience.billing.google.service_account_json' => json_encode([
                'client_email' => 'play@example.iam.gserviceaccount.com',
                'private_key' => str_replace("\n", '\\n', $pem),
            ], JSON_THROW_ON_ERROR),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.test',
                'expires_in' => 3600,
            ]),
            'https://androidpublisher.googleapis.com/*' => Http::response([
                'subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE',
                'lineItems' => [[
                    'productId' => 'wayoa_monthly',
                    'expiryTime' => '2026-12-01T00:00:00Z',
                    'autoRenewingPlan' => new \stdClass,
                ]],
            ]),
        ]);

        $detail = app(GooglePlaySubscriptionReader::class)->read('purchase-token');

        $this->assertNotNull($detail);
        $this->assertSame('wayoa_monthly', $detail['productId']);
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token');
    }
}
