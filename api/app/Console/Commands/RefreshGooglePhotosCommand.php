<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Destinations\Models\Neighbourhood;
use App\Domains\Experiences\Models\Experience;
use App\Domains\ExternalSources\Providers\GooglePlacesProvider;
use App\Domains\Places\Actions\RefreshGooglePhoto;
use Illuminate\Console\Command;
use Throwable;

/**
 * Repairs stored Google photo names that Google has stopped recognising.
 *
 * Each distinct name is checked against the media endpoint; only the ones it
 * rejects are replaced, with a fresh photo of the same place. The same repair
 * happens lazily when a phone asks for a dead photo, so this exists to fix the
 * whole catalogue in one go rather than card by card.
 */
class RefreshGooglePhotosCommand extends Command
{
    protected $signature = 'experience:refresh-google-photos
                            {--limit=500 : Maximum distinct photo names to check}
                            {--dry-run : Report which names are dead without replacing them}';

    protected $description = 'Replace Google photo references that Google no longer recognises';

    public function handle(GooglePlacesProvider $google, RefreshGooglePhoto $refresh): int
    {
        if (! $google->isConfigured()) {
            $this->error('GOOGLE_PLACES_API_KEY is not set.');

            return self::FAILURE;
        }

        $prefix = GooglePlacesProvider::PHOTO_PREFIX;

        $stored = collect()
            ->merge(Experience::query()->where('image_url', 'like', $prefix.'%')->pluck('image_url'))
            ->merge(Neighbourhood::query()->where('image_url', 'like', $prefix.'%')->pluck('image_url'))
            ->merge(Destination::query()->where('hero_image_url', 'like', $prefix.'%')->pluck('hero_image_url'))
            ->unique()
            ->take((int) $this->option('limit'))
            ->values();

        $dry = (bool) $this->option('dry-run');
        $tally = ['live' => 0, 'refreshed' => 0, 'no_photo' => 0, 'unreadable' => 0, 'failed' => 0];

        foreach ($stored as $value) {
            try {
                $name = substr($value, strlen($prefix));

                if ($google->mediaUriFor($name) !== null) {
                    $tally['live']++;

                    continue;
                }

                if (! RefreshGooglePhoto::canRefresh($value)) {
                    $tally['unreadable']++;
                    $this->warn($value.': not a places/{placeId}/photos/{photoId} name, so no fresh photo can be fetched.');

                    continue;
                }

                if ($dry) {
                    $tally['refreshed']++;

                    continue;
                }

                $tally[$refresh->refresh($value) === null ? 'no_photo' : 'refreshed']++;
            } catch (Throwable $e) {
                $tally['failed']++;
                $this->warn($value.': '.$e->getMessage());
            }
        }

        $this->table(
            ['Checked', 'Still valid', $dry ? 'Would refresh' : 'Refreshed', 'Place has no photo', 'Unreadable name', 'Failed'],
            [[$stored->count(), $tally['live'], $tally['refreshed'], $tally['no_photo'], $tally['unreadable'], $tally['failed']]],
        );

        return self::SUCCESS;
    }
}
