<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\DTO;

use App\Domains\Shared\ValueObjects\GeoPoint;
use Carbon\CarbonImmutable;

final readonly class LiveEvent
{
    public function __construct(
        public string $id,
        public string $source,
        public string $title,
        public ?string $category,
        public ?string $genre,
        public CarbonImmutable $startsAt,
        /* False when the listing gives a date but no time ("doors TBC"). */
        public bool $timeKnown,
        public ?string $venueName,
        public ?GeoPoint $venue,
        public ?int $priceFromMinor,
        public ?string $currency,
        public ?string $imageUrl,
        public ?string $ticketUrl,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(?GeoPoint $from = null): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'title' => $this->title,
            'category' => $this->category,
            'genre' => $this->genre,
            'starts_at' => $this->startsAt->toIso8601String(),
            'time_known' => $this->timeKnown,
            'venue' => $this->venueName === null && $this->venue === null ? null : [
                'name' => $this->venueName,
                'lat' => $this->venue?->lat,
                'lng' => $this->venue?->lng,
            ],
            'distance_metres' => $from !== null && $this->venue !== null
                ? (int) round($from->distanceTo($this->venue))
                : null,
            'price_from_minor' => $this->priceFromMinor,
            'currency' => $this->currency,
            'image_url' => $this->imageUrl,
            'ticket_url' => $this->ticketUrl,
        ];
    }
}
