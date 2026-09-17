<?php

declare(strict_types=1);

namespace App\Domains\Journeys\Models;

use App\Domains\Destinations\Models\Destination;
use App\Domains\Shared\Concerns\BelongsToActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Journey extends Model
{
    use BelongsToActor, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'child_ages' => 'array',
            'mission_goals' => 'array',
            'must_do' => 'array',
            'avoid' => 'array',
            'accessibility_mode' => 'boolean',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function anchors(): HasMany
    {
        return $this->hasMany(JourneyAnchor::class)->orderBy('starts_at');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(JourneyGoal::class);
    }

    public function playbook(): array
    {
        return config("experience.playbooks.{$this->reason}", config('experience.playbooks.other'));
    }

    public function partySize(): int
    {
        return $this->adults + $this->children;
    }

    public function hasChildren(): bool
    {
        return $this->children > 0;
    }

    public function youngestChildAge(): ?int
    {
        $ages = array_filter((array) $this->child_ages, 'is_numeric');

        return $ages === [] ? null : (int) min($ages);
    }
}
