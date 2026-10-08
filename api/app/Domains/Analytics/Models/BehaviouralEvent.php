<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Models;

use App\Domains\Shared\Concerns\BelongsToActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BehaviouralEvent extends Model
{
    use BelongsToActor, HasUuids;

    /** Spec s20. */
    public const TYPES = [
        'impression', 'view', 'save', 'unsave', 'book', 'skip', 'share', 'navigate',
        'complete', 'rate', 'search', 'add_to_itinerary', 'remove_from_itinerary',
        'accept_recommendation', 'reject_recommendation',
        'destination_search_submitted', 'destination_candidate_viewed',
        'destination_activation_requested', 'destination_import_completed',
        'destination_import_failed', 'destination_selected_after_import',
        'destination_activation_abandoned',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['properties' => 'array', 'occurred_at' => 'immutable_datetime'];
    }
}
