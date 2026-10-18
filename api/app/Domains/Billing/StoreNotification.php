<?php

declare(strict_types=1);

namespace App\Domains\Billing;

use Carbon\CarbonImmutable;

/**
 * One thing a store told us, in terms this domain understands.
 *
 * Apple and Google describe the same handful of events in entirely different
 * shapes. Normalising at the edge means the rule that decides access is
 * written once and reads the same for both, and that adding a third store
 * later is a mapper rather than a second set of rules.
 *
 * `paidUntil` is only ever set from a *successful* charge. A grace period
 * extends the store's own expiry without anyone paying, so a mapper that is
 * handling grace leaves this null and the period already recorded stands.
 */
final readonly class StoreNotification
{
    public function __construct(
        public string $store,
        public string $originalTransactionId,
        public SubscriptionState $state,
        public ?string $productId = null,
        public ?CarbonImmutable $paidUntil = null,
        public ?bool $autoRenew = null,
        public ?CarbonImmutable $pauseResumesAt = null,
        public string $environment = 'production',
        /** The store's own id for this delivery, where it gives one. */
        public ?string $notificationId = null,
        public string $type = 'unknown',
        public array $payload = [],
    ) {}
}
