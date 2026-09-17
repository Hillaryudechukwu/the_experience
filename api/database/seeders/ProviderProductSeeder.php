<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\ExternalSources\Contracts\ProviderCapability;
use App\Domains\ExternalSources\Models\ExternalEntity;
use App\Domains\ExternalSources\Models\ProviderProduct;
use App\Domains\Experiences\Models\Experience;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Bookable products against canonical experiences.
 *
 * One canonical place can carry several provider products (spec s11); the
 * external_entities rows keep provider ids out of our own primary keys.
 *
 * Both suppliers seeded here are non-commercial: `sandbox` is our own reference
 * implementation of the provider contract, and `deeplink` is a generic affiliate
 * redirect. Neither claims to be a real merchant feed.
 */
class ProviderProductSeeder extends Seeder
{
    public function run(): void
    {
        $verified = CarbonImmutable::now();

        $experiences = Experience::with('destination')
            ->whereNotNull('price_from_minor')
            ->orWhere('requires_booking', true)
            ->get();

        foreach ($experiences as $experience) {
            $sandbox = ProviderProduct::updateOrCreate(
                ['provider' => 'sandbox', 'provider_product_id' => 'SBX-' . strtoupper(substr(md5($experience->slug), 0, 10))],
                [
                    'experience_id' => $experience->id,
                    'place_id' => $experience->place_id,
                    'title' => $experience->title . ' — timed entry',
                    'description' => 'Timed-entry admission. Sandbox supplier used to exercise the native booking path end to end.',
                    'product_url' => null,
                    'capabilities' => [
                        ProviderCapability::CONTENT,
                        ProviderCapability::SEARCH,
                        ProviderCapability::LIVE_AVAILABILITY,
                        ProviderCapability::LIVE_PRICING,
                        ProviderCapability::NATIVE_BOOKING,
                        ProviderCapability::CANCELLATION,
                    ],
                    'cancellation_policy' => 'Free cancellation up to 24 hours before the start time.',
                    'currency' => $experience->currency ?? $experience->destination->currency,
                    'price_from_minor' => $experience->price_from_minor,
                    'price_verified_at' => $verified,
                    'last_synced_at' => $verified,
                    'is_active' => true,
                ],
            );

            $deeplink = ProviderProduct::updateOrCreate(
                ['provider' => 'deeplink', 'provider_product_id' => 'DL-' . strtoupper(substr(md5($experience->slug), 0, 10))],
                [
                    'experience_id' => $experience->id,
                    'place_id' => $experience->place_id,
                    'title' => $experience->title . ' — official tickets',
                    'description' => 'Checkout completes on the merchant\'s own site.',
                    'product_url' => config('experience.providers.deeplink.base_url'),
                    'capabilities' => [
                        ProviderCapability::CONTENT,
                        ProviderCapability::SEARCH,
                        ProviderCapability::REDIRECT_BOOKING,
                    ],
                    'cancellation_policy' => 'Cancellation terms are set by the merchant at checkout.',
                    'currency' => $experience->currency ?? $experience->destination->currency,
                    'price_from_minor' => $experience->price_from_minor,
                    'price_verified_at' => $verified,
                    'last_synced_at' => $verified,
                    'is_active' => true,
                ],
            );

            foreach ([$sandbox, $deeplink] as $product) {
                ExternalEntity::updateOrCreate(
                    [
                        'provider' => $product->provider,
                        'provider_id' => $product->provider_product_id,
                        'entity_type' => 'experience',
                    ],
                    [
                        'entity_id' => $experience->id,
                        'external_url' => $product->product_url,
                        'metadata' => ['seeded' => true],
                        'confidence' => 1.0,
                        'last_synced_at' => $verified,
                    ],
                );
            }
        }
    }
}
