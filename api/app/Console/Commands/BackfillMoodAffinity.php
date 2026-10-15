<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Experiences\Models\Experience;
use App\Domains\Places\Services\ExperienceDraftFactory;
use Illuminate\Console\Command;

/**
 * Fills mood affinity on imported places that were saved with an empty map.
 *
 * Seeded editorial rows are left alone. Running it twice changes nothing.
 */
class BackfillMoodAffinity extends Command
{
    protected $signature = 'experiences:backfill-mood-affinity';

    protected $description = 'Fill empty derived mood affinity from place kind';

    public function handle(): int
    {
        $updated = 0;

        Experience::query()
            ->with('place')
            ->whereJsonLength('mood_affinity', 0)
            ->where('data_source', 'not like', 'seed_%')
            ->whereNotIn('data_source', ['editorial'])
            ->orderBy('id')
            ->chunkById(100, function ($experiences) use (&$updated) {
                foreach ($experiences as $experience) {
                    $kind = (string) ($experience->place?->kind ?? '');
                    $affinity = ExperienceDraftFactory::MOOD_AFFINITY[$kind] ?? null;
                    if ($affinity === null || $affinity === []) {
                        continue;
                    }

                    $experience->update(['mood_affinity' => $affinity]);
                    $updated++;
                }
            });

        $this->info("Updated {$updated} experiences.");

        return self::SUCCESS;
    }
}
