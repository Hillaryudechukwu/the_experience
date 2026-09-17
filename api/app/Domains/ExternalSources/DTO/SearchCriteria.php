<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\DTO;

use App\Domains\Shared\ValueObjects\GeoPoint;

final readonly class SearchCriteria
{
    public function __construct(
        public ?string $query = null,
        public ?GeoPoint $near = null,
        public ?int $radiusMetres = null,
        public ?string $destinationSlug = null,
        public ?DateRange $dates = null,
        public int $limit = 25,
        public array $categories = [],
    ) {}
}
