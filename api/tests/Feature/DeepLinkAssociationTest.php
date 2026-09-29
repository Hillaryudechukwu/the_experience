<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Android App Links and iOS Universal Links.
 *
 * Both platforms verify that an app may open a domain's links by fetching a
 * file from that domain. The file is therefore the security boundary, and the
 * failure mode that matters is not a 404 — it is serving something incomplete,
 * because Android caches a failed verification and a cached failure is far
 * harder to notice than a missing file.
 */
class DeepLinkAssociationTest extends TestCase
{
    public function test_android_association_names_the_package_and_its_fingerprints(): void
    {
        config([
            'experience.app_links.android_package' => 'uk.co.synteric.experience',
            'experience.app_links.android_sha256' => ['AA:BB:CC'],
        ]);

        $response = $this->getJson('/.well-known/assetlinks.json');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonPath('0.target.package_name', 'uk.co.synteric.experience');
        $response->assertJsonPath('0.target.sha256_cert_fingerprints', ['AA:BB:CC']);
        $response->assertJsonPath('0.relation', ['delegate_permission/common.handle_all_urls']);
    }

    public function test_android_association_is_absent_until_a_fingerprint_exists(): void
    {
        config(['experience.app_links.android_sha256' => []]);

        /* Nothing rather than an association that cannot verify. EAS does not
           produce the signing fingerprint until the first build, so this is the
           normal state of a fresh deployment, not an error. */
        $this->getJson('/.well-known/assetlinks.json')->assertNotFound();
    }

    public function test_apple_association_names_the_team_qualified_app_id(): void
    {
        config([
            'experience.app_links.ios_team_id' => 'ABCDE12345',
            'experience.app_links.ios_bundle_id' => 'uk.co.synteric.experience',
            'experience.app_links.paths' => ['/experience/*'],
        ]);

        $response = $this->getJson('/.well-known/apple-app-site-association');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonPath('applinks.details.0.appIDs', ['ABCDE12345.uk.co.synteric.experience']);
        $response->assertJsonPath('applinks.details.0.components.0./', '/experience/*');
    }

    public function test_apple_association_is_absent_without_a_team_id(): void
    {
        config(['experience.app_links.ios_team_id' => null]);

        $this->getJson('/.well-known/apple-app-site-association')->assertNotFound();
    }

    /**
     * The app captures only what it can draw. A claimed path with no screen
     * behind it takes the link away from the browser that could have shown it
     * and dead-ends the traveller inside the app instead.
     */
    public function test_only_routes_the_app_can_render_are_claimed(): void
    {
        $this->assertSame(['/experience/*'], config('experience.app_links.paths'));
    }
}
