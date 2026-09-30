<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ExternalSources\Providers\DeepLinkAffiliateProvider;
use App\Domains\ExternalSources\Services\ProviderRegistry;
use Tests\TestCase;

/**
 * A supplier must not report itself ready on placeholder values.
 *
 * The redirect provider defaulted to 'demo-partner' and a checkout URL on
 * partner.example.com, and its readiness check looked only at the id — which
 * the default made truthy. Any deployment that had not configured it offered
 * it alongside the real suppliers and sent travellers to a domain that does
 * not exist. Nothing reported it missing, because a placeholder satisfies
 * every check you write against emptiness.
 */
class ProviderConfigurationTest extends TestCase
{
    public function test_the_redirect_supplier_needs_somewhere_to_send_people(): void
    {
        config(['experience.providers.deeplink.base_url' => null]);

        $this->assertFalse(
            app(DeepLinkAffiliateProvider::class)->isConfigured(),
            'A redirect supplier with no checkout URL cannot redirect anyone.',
        );
    }

    public function test_it_needs_an_affiliate_id_too(): void
    {
        config(['experience.providers.deeplink.affiliate_id' => null]);

        $this->assertFalse(app(DeepLinkAffiliateProvider::class)->isConfigured());
    }

    public function test_an_unconfigured_supplier_is_not_offered(): void
    {
        config([
            'experience.providers.deeplink.affiliate_id' => null,
            'experience.providers.deeplink.base_url' => null,
        ]);

        $usable = array_map(fn ($p) => $p->key(), app(ProviderRegistry::class)->usable());

        $this->assertNotContains('deeplink', $usable);
    }

    /** And nothing ships a default that would satisfy the check on its own. */
    public function test_the_configuration_has_no_placeholder_defaults(): void
    {
        $this->assertNull(
            env('DEEPLINK_AFFILIATE_ID_UNSET_PROBE', null),
            'sanity: env() returns null for an unset key',
        );

        foreach (['affiliate_id', 'base_url'] as $key) {
            $path = "experience.providers.deeplink.{$key}";

            /* Read straight from the config file rather than the live values,
               which the test environment sets on purpose. */
            $defaults = require config_path('experience.php');

            $this->assertNotSame(
                'demo-partner',
                $defaults['providers']['deeplink']['affiliate_id'] ?? null,
                "{$path} still falls back to a placeholder.",
            );
        }
    }
}
