<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\DTO;

use App\Domains\Shared\ValueObjects\Freshness;
use App\Domains\Shared\ValueObjects\GeoPoint;

/**
 * A real-world place as one provider describes it.
 *
 * Not yet a canonical place: PlaceResolver decides whether this is something we
 * already know about, something new, or something a human should look at.
 */
final readonly class PlaceCandidate
{
    /**
     * @param  array<string,string>  $externalRefs  e.g. ['wikidata' => 'Q62378']
     * @param  array<string,mixed>   $accessibility
     * @param  list<string>          $categories
     */
    public function __construct(
        public string $provider,
        public string $providerId,
        public string $name,
        public GeoPoint $point,
        public ?string $address = null,
        public string $kind = 'attraction',
        public ?array $openingHours = null,
        public ?float $openingHoursConfidence = null,
        public ?string $website = null,
        public ?string $phone = null,
        public ?float $rating = null,
        public ?int $ratingCount = null,
        public array $accessibility = [],
        public array $externalRefs = [],
        public array $categories = [],
        public array $raw = [],
    ) {}

    public function normalisedName(): string
    {
        $stripped = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($this->name));

        return trim((string) preg_replace('/\s+/', ' ', (string) $stripped));
    }

    public function freshness(): Freshness
    {
        return Freshness::live($this->provider, Freshness::SEMI_DYNAMIC);
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'provider_id' => $this->providerId,
            'name' => $this->name,
            'lat' => $this->point->lat,
            'lng' => $this->point->lng,
            'address' => $this->address,
            'kind' => $this->kind,
            'opening_hours' => $this->openingHours,
            'website' => $this->website,
            'phone' => $this->phone,
            'rating' => $this->rating,
            'rating_count' => $this->ratingCount,
            'accessibility' => $this->accessibility,
            'external_refs' => $this->externalRefs,
            'categories' => $this->categories,
        ];
    }
}
