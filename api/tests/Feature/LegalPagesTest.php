<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Subscription JEM §11 P0 — somewhere honest to point the stores.
 *
 * The statements asserted here are not decoration: Apple and Google both
 * require the price, the renewal and the way out to be legible before
 * purchase, and the terms are the page the listing links to.
 */
class LegalPagesTest extends TestCase
{
    public function test_both_pages_answer_without_a_login(): void
    {
        $this->get('/legal/privacy')->assertOk();
        $this->get('/legal/terms')->assertOk();
        $this->get('/legal/delete-account')->assertOk();
    }

    public function test_the_delete_account_page_explains_in_app_and_email_paths(): void
    {
        $page = $this->get('/legal/delete-account')->assertOk();

        $page->assertSee('How to request deletion', false);
        $page->assertSee('Data that is deleted', false);
        $page->assertSee('Data that is kept, and for how long', false);
        $page->assertSee('Six years from the booking, then deleted automatically', false);
        $page->assertSee('not</strong> cancel', false);
    }

    public function test_the_terms_state_what_the_stores_require(): void
    {
        $page = $this->get('/legal/terms')->assertOk();

        /* Price, period and that it renews until stopped. */
        $page->assertSee('£1.99', false);
        $page->assertSee('One month', false);
        $page->assertSee('renews automatically until you stop it', false);

        /* The month already paid for is never cut short. */
        $page->assertSee('always runs to its end', false);

        /* The two platforms genuinely differ, and saying otherwise on either
           one is the kind of thing review rejects. */
        $page->assertSee('Google Play has a real pause', false);
        $page->assertSee('Apple has no pause', false);

        /* No card, no refunds from us, and deletion is not cancellation. */
        $page->assertSee('never see or store your card', false);
        $page->assertSee('Refunds are requested from Apple or Google', false);
        $page->assertSee('does not cancel your subscription', false);
    }

    public function test_the_privacy_page_says_what_a_subscription_stores(): void
    {
        $page = $this->get('/legal/privacy')->assertOk();

        $page->assertSee('Subscriptions', false);
        $page->assertSee('We never receive your card', false);
        $page->assertSee('the start and end of the period you have paid for', false);
    }

    /** Each page links to the other, which is what the stores check for. */
    public function test_the_pages_point_at_each_other(): void
    {
        $this->get('/legal/terms')->assertSee('Privacy Policy', false);
        $this->get('/legal/privacy')->assertSee('Terms of Use', false);
    }

    public function test_privacy_uses_the_configured_delete_account_url(): void
    {
        config([
            'experience.legal.delete_account_url' => 'https://example.test/legal/delete-account',
        ]);

        $this->get('/legal/privacy')
            ->assertOk()
            ->assertSee('https://example.test/legal/delete-account', false);
    }

    /** The store-listed page has to point at the published pages, not the fallbacks. */
    public function test_the_delete_account_page_uses_the_configured_links_and_contact(): void
    {
        config([
            'experience.legal.privacy_url' => 'https://example.test/privacy',
            'experience.legal.terms_url' => 'https://example.test/legal/terms',
            'experience.legal.delete_account_url' => 'https://example.test/legal/delete-account',
            'experience.legal.privacy_contact' => 'erase@example.test',
        ]);

        $this->get('/legal/delete-account')
            ->assertOk()
            ->assertSee('href="https://example.test/privacy"', false)
            ->assertSee('href="https://example.test/legal/terms"', false)
            ->assertSee('<link rel="canonical" href="https://example.test/legal/delete-account">', false)
            ->assertSee('mailto:erase@example.test', false);

        $directory = 'storage/app/testing-legal-config';

        try {
            $this->artisan('experience:export-legal', ['--path' => $directory])->assertSuccessful();

            $exported = File::get(base_path($directory . '/delete-account.html'));
            $this->assertStringContainsString('href="https://example.test/privacy"', $exported);
            $this->assertStringContainsString('href="https://example.test/legal/terms"', $exported);
            $this->assertStringContainsString('mailto:erase@example.test', $exported);
        } finally {
            File::deleteDirectory(base_path($directory));
        }
    }

    /** /privacy and /legal/privacy are one page; the canonical says which URL counts. */
    public function test_privacy_names_its_canonical_url(): void
    {
        config(['experience.legal.privacy_url' => 'https://example.test/privacy']);

        $this->get('/legal/privacy')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="https://example.test/privacy">', false);
    }

    public function test_the_export_writes_both_pages(): void
    {
        $directory = 'storage/app/testing-legal';

        $this->artisan('experience:export-legal', ['--path' => $directory])
            ->assertSuccessful();

        foreach (['privacy.html', 'terms.html', 'delete-account.html'] as $name) {
            $file = base_path($directory . '/' . $name);
            $this->assertFileExists($file);
            $this->assertStringContainsString('<!doctype html>', File::get($file));
        }

        File::deleteDirectory(base_path($directory));
    }
}
