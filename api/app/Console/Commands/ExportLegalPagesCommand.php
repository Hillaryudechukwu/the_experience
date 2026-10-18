<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Writes the legal pages out as plain HTML.
 *
 * The store listing carries the privacy policy URL, and that URL has to keep
 * working for as long as the app is listed — through a backend migration, a
 * provider change, an outage. Tying it to wherever the API happens to be
 * deployed makes a compliance obligation depend on an infrastructure decision.
 *
 * So the policy is authored once, here, and can be published anywhere that
 * serves a file. The route stays as the canonical source and the fallback; this
 * produces the copy that goes on the domain the listing points at.
 */
class ExportLegalPagesCommand extends Command
{
    protected $signature = 'experience:export-legal
                            {--path=storage/app/legal : Directory to write into}';

    protected $description = 'Render the legal pages to static HTML for hosting on the public domain';

    public function handle(): int
    {
        $directory = base_path((string) $this->option('path'));

        File::ensureDirectoryExists($directory);

        $contact = config('experience.legal.privacy_contact') ?: 'privacy@example.com';

        $pages = [
            'privacy.html' => view('legal.privacy', [
                'updated' => config('experience.legal.privacy_updated'),
                'contact' => $contact,
                'termsUrl' => config('experience.legal.terms_url') ?: route('legal.terms'),
            ]),
            'terms.html' => view('legal.terms', [
                'updated' => config('experience.legal.terms_updated'),
                'contact' => $contact,
                'price' => config('experience.legal.subscription_price'),
                'privacyUrl' => config('experience.legal.privacy_url') ?: route('legal.privacy'),
            ]),
        ];

        foreach ($pages as $name => $view) {
            $file = $directory . '/' . $name;
            File::put($file, $view->render());
            $this->info('Wrote ' . $file);
        }

        $this->line('');
        $this->line('Publish both, then give both URLs to the store listings. A subscription needs the terms link as well as the policy.');

        foreach (['PRIVACY_POLICY_URL' => 'privacy_url', 'TERMS_URL' => 'terms_url'] as $variable => $key) {
            $configured = config('experience.legal.' . $key);

            if (blank($configured)) {
                $this->warn($variable . ' is not set, so the app still links to this server\'s own copy.');
            } else {
                $this->line($variable . ': ' . $configured);
            }
        }

        return self::SUCCESS;
    }
}
