<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use Illuminate\Support\Facades\Log;

/**
 * Verifies a StoreKit 2 signed transaction JWS the device posts at purchase
 * or restore time (Subscription JEM §11 P4).
 *
 * Same trust model as App Store Server Notifications: the leaf in `x5c` must
 * chain to Apple's configured root. An unverified body is not a receipt.
 */
class AppleTransactionVerifier
{
    public function __construct(private readonly AppleNotificationVerifier $notifications) {}

    /**
     * @return array<string, mixed>|null Decoded transaction claims, or null when untrusted.
     */
    public function verify(string $signedTransaction): ?array
    {
        /* Transaction JWS uses the same x5c chain as notification payloads;
           bundleId sits on the claims root rather than under data. */
        $claims = $this->notifications->verify($signedTransaction);

        if ($claims === null) {
            return null;
        }

        $product = (string) config('experience.billing.products.apple');
        $claimedProduct = (string) ($claims['productId'] ?? '');

        if ($product !== '' && $claimedProduct !== '' && ! hash_equals($product, $claimedProduct)) {
            Log::warning('Apple transaction refused: unexpected product id.');

            return null;
        }

        $original = (string) ($claims['originalTransactionId'] ?? '');

        if ($original === '') {
            return null;
        }

        return $claims;
    }
}
