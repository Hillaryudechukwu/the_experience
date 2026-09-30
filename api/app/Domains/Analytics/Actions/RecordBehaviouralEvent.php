<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Actions;

use App\Domains\Analytics\Models\BehaviouralEvent;
use App\Domains\Shared\ValueObjects\Actor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Behavioural events (spec s20).
 *
 * Kept deliberately separate from transactional truth: this table informs
 * ranking and product metrics, never booking or payment state.
 */
class RecordBehaviouralEvent
{
    public function record(Actor $actor, string $type, array $attributes = []): ?BehaviouralEvent
    {
        if (! in_array($type, BehaviouralEvent::TYPES, true)) {
            throw new InvalidArgumentException("Unknown behavioural event type: {$type}");
        }

        return BehaviouralEvent::create(array_merge($actor->ownerAttributes(), $this->row($type, $attributes)));
    }

    /**
     * Several events in one statement.
     *
     * A discovery response records an impression per card, which was an insert
     * per result — twelve round trips on a full page, for rows that inform
     * ranking and nothing transactional. They are written together instead.
     *
     * @param  list<array{0: string, 1: array<string, mixed>}>  $events
     */
    public function recordMany(Actor $actor, array $events): void
    {
        if ($events === []) {
            return;
        }

        $owner = $actor->ownerAttributes();
        $rows = [];

        foreach ($events as [$type, $attributes]) {
            if (! in_array($type, BehaviouralEvent::TYPES, true)) {
                throw new InvalidArgumentException("Unknown behavioural event type: {$type}");
            }

            $row = array_merge($owner, $this->row($type, $attributes));

            /* insert() bypasses the model, so the things Eloquent would have
               done have to be done here: the key, the timestamps, and the JSON
               encoding the `properties` cast normally handles. */
            $row['id'] = (string) Str::uuid7();
            $row['properties'] = json_encode($row['properties'], JSON_THROW_ON_ERROR);
            $row['created_at'] = $row['updated_at'] = CarbonImmutable::now();

            $rows[] = $row;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            BehaviouralEvent::insert($chunk);
        }
    }

    /** @return array<string, mixed> */
    private function row(string $type, array $attributes): array
    {
        return [
            'type' => $type,
            'subject_type' => $attributes['subject_type'] ?? null,
            'subject_id' => $attributes['subject_id'] ?? null,
            'recommendation_set_id' => $attributes['recommendation_set_id'] ?? null,
            'surface' => $attributes['surface'] ?? null,
            'properties' => $attributes['properties'] ?? [],
            'occurred_at' => $attributes['occurred_at'] ?? CarbonImmutable::now(),
        ];
    }
}
