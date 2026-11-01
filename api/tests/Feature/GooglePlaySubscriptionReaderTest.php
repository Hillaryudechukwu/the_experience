<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Services\GooglePlaySubscriptionReader;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GooglePlaySubscriptionReaderTest extends TestCase
{
    public function test_returns_null_when_credentials_are_missing(): void
    {
        config([
            'experience.billing.google.package_name' => 'uk.co.synteric.experience',
            'experience.billing.google.service_account_json' => '',
        ]);

        $this->assertNull(app(GooglePlaySubscriptionReader::class)->read('token'));
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
}
