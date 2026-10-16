<?php

declare(strict_types=1);

namespace App\Domains\Discovery\Services;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Discovery\Models\LiveIntent;
use App\Domains\Journeys\Models\Journey;
use App\Domains\Shared\ValueObjects\Actor;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The only writer of live intent.
 *
 * One row per actor and journey. A request field wins over the stored one.
 * An explicit null clears that field and leaves the rest until local midnight.
 * Coordinates are never written here.
 */
class LiveIntentStore
{
    public function mergeInto(Actor $actor, array $input): array
    {
        if (! config('experience.scoring.use_live_intent', true)) {
            return $input;
        }

        $row = $this->active($actor, $this->journeyId($actor, $input));
        if ($row === null) {
            return $input;
        }

        if (! array_key_exists('mood', $input) && $row->mood !== null) {
            $input['mood'] = $row->mood;
        }
        if (! array_key_exists('window_minutes', $input) && $row->window_minutes !== null) {
            $input['window_minutes'] = (int) $row->window_minutes;
        }
        if (! array_key_exists('categories', $input) && $row->categories !== []) {
            $input['categories'] = $row->categories;
        }
        if (! array_key_exists('exclude_categories', $input) && $row->exclude_categories !== []) {
            $input['exclude_categories'] = $row->exclude_categories;
        }
        if (! array_key_exists('free_only', $input) && $row->free_only) {
            $input['free_only'] = true;
        }

        return $input;
    }

    /** @param array<string, mixed> $fields */
    public function upsert(Actor $actor, array $input, array $fields, string $source): ?LiveIntent
    {
        if ($actor->isAnonymous() || $fields === [] || ! config('experience.scoring.use_live_intent', true)) {
            return null;
        }

        $journeyId = $this->journeyId($actor, $input);
        $row = $this->active($actor, $journeyId);
        $expires = CarbonImmutable::now($this->timezone($actor, $input, $journeyId))->endOfDay();

        if ($row === null) {
            $owner = $actor->ownerAttributes();

            try {
                return LiveIntent::create(array_merge($owner, [
                    'owner_key' => LiveIntent::ownerKeyFor(
                        $actor->userId,
                        $owner['guest_session_id'] ?? null,
                        $journeyId,
                    ),
                    'journey_id' => $journeyId,
                    'categories' => [],
                    'exclude_categories' => [],
                    'free_only' => false,
                    'source' => $source,
                    'expires_at' => $expires,
                ], $fields));
            } catch (UniqueConstraintViolationException) {
                /*
                 * Another request inserted the row between our read and this
                 * write — which is the normal case when a mood is tapped while
                 * the first discovery fetch is still in flight. The unique
                 * index turned what used to be a silent second row into this,
                 * so the loser now updates the winner's row instead.
                 */
                $row = $this->active($actor, $journeyId);

                if ($row === null) {
                    return null;
                }
            }
        }

        $row->fill($fields);
        $row->source = $source;
        $row->expires_at = $expires;
        $row->save();

        return $row;
    }

    /** @param list<string> $fields */
    public function clear(Actor $actor, array $input, array $fields): void
    {
        $row = $this->active($actor, $this->journeyId($actor, $input));
        if ($row === null) {
            return;
        }

        foreach ($fields as $field) {
            match ($field) {
                'mood' => $row->mood = null,
                'window_minutes' => $row->window_minutes = null,
                'categories' => $row->categories = [],
                'exclude_categories' => $row->exclude_categories = [],
                'free_only' => $row->free_only = false,
                default => null,
            };
        }

        $row->save();
    }

    /** @param array<string, mixed> $parsed */
    public function rememberParsed(Actor $actor, array $input, array $parsed): void
    {
        $fields = [];
        $clears = $parsed['clear'] ?? [];

        if (in_array('mood', $clears, true)) {
            $fields['mood'] = null;
        } elseif (($parsed['mood'] ?? null) !== null) {
            $fields['mood'] = $parsed['mood'];
        }

        if (($parsed['window_minutes'] ?? null) !== null) {
            $fields['window_minutes'] = (int) $parsed['window_minutes'];
        }

        $filters = $parsed['filters'] ?? [];
        if (! empty($filters['categories'])) {
            $fields['categories'] = array_values($filters['categories']);
        }
        if (in_array('exclude_categories', $clears, true)) {
            $fields['exclude_categories'] = [];
        } elseif (! empty($filters['exclude_categories'])) {
            $fields['exclude_categories'] = array_values($filters['exclude_categories']);
        }
        if (! empty($filters['free_only'])) {
            $fields['free_only'] = true;
        }

        $this->upsert($actor, $input, $fields, 'assistant');
    }

    public function recordDiscovery(Actor $actor, array $input): void
    {
        $fields = [];
        $surface = $input['surface'] ?? null;

        if ($surface === 'mood' && ! empty($input['mood'])) {
            $fields['mood'] = $input['mood'];
        }
        if ($surface === 'time_boxed' && ! empty($input['window_minutes'])) {
            $fields['window_minutes'] = (int) $input['window_minutes'];
        }

        $this->upsert($actor, $input, $fields, 'discover');
    }

    private function active(Actor $actor, ?string $journeyId): ?LiveIntent
    {
        if ($actor->isAnonymous()) {
            return null;
        }

        /* By the key the unique index is on, so there is exactly one row this
           can return rather than whichever of several the database offered. */
        $row = LiveIntent::query()
            ->ownedBy($actor)
            ->where('owner_key', LiveIntent::ownerKeyFor(
                $actor->userId,
                $actor->guestSessionId,
                $journeyId,
            ))
            ->first();
        if ($row === null) {
            return null;
        }

        if ($row->expires_at->lessThanOrEqualTo(CarbonImmutable::now())) {
            $row->delete();

            return null;
        }

        return $row;
    }

    private function journeyId(Actor $actor, array $input): ?string
    {
        if (! empty($input['journey_id'])) {
            $id = Journey::query()->ownedBy($actor)->whereKey($input['journey_id'])->value('id');

            return $id === null ? null : (string) $id;
        }

        $id = Journey::query()->ownedBy($actor)->where('status', 'active')->latest('updated_at')->value('id');

        return $id === null ? null : (string) $id;
    }

    private function timezone(Actor $actor, array $input, ?string $journeyId): string
    {
        if ($journeyId !== null) {
            $timezone = Journey::query()->with('destination')->find($journeyId)?->destination?->timezone;
            if (is_string($timezone) && $timezone !== '') {
                return $timezone;
            }
        }

        $destination = null;
        if (! empty($input['destination_id'])) {
            $destination = Destination::find($input['destination_id']);
        } elseif (! empty($input['destination'])) {
            $destination = Destination::where('slug', $input['destination'])->first();
        }

        /* Null-safe: both lookups above return null for a destination the
           catalogue does not hold, which is reachable now that search offers
           geocoded places we do not cover. */
        return $destination?->timezone ?? 'UTC';
    }
}
