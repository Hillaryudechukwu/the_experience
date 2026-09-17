<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Actions;

use App\Domains\Analytics\Models\BehaviouralEvent;
use App\Domains\Shared\ValueObjects\Actor;
use Carbon\CarbonImmutable;
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

        return BehaviouralEvent::create(array_merge($actor->ownerAttributes(), [
            'type' => $type,
            'subject_type' => $attributes['subject_type'] ?? null,
            'subject_id' => $attributes['subject_id'] ?? null,
            'recommendation_set_id' => $attributes['recommendation_set_id'] ?? null,
            'surface' => $attributes['surface'] ?? null,
            'properties' => $attributes['properties'] ?? [],
            'occurred_at' => $attributes['occurred_at'] ?? CarbonImmutable::now(),
        ]));
    }
}
