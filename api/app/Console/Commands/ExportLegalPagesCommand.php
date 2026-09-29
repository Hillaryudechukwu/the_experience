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

        $html = view('legal.privacy', [
            'updated' => config('experience.legal.privacy_updated'),
            'contact' => config('experience.legal.privacy_contact') ?: 'privacy@example.com',
        ])->render();

        $file = $directory . '/privacy.html';
        File::put($file, $html);

        $this->info('Wrote ' . $file);
        $this->line('');
        $this->line('Publish it at the URL in PRIVACY_POLICY_URL, then give that URL to both store listings.');

        $configured = config('experience.legal.privacy_url');
        if (blank($configured)) {
            $this->warn('PRIVACY_POLICY_URL is not set, so the app still links to this server\'s own copy.');
        } else {
            $this->line('Currently configured: ' . $configured);
        }

        return self::SUCCESS;
    }
}
