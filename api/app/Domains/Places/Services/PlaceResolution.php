<?php

declare(strict_types=1);

namespace App\Domains\Places\Services;

use App\Domains\Places\Models\Place;

final readonly class PlaceResolution
{
    public const MATCHED = 'matched';
    public const CREATED = 'created';
    public const NEEDS_REVIEW = 'needs_review';

    public function __construct(
        public string $decision,
        public ?Place $place,
        public float $confidence,
        public array $signals = [],
    ) {}

    public function isMatch(): bool
    {
        return $this->decision === self::MATCHED;
    }
}
