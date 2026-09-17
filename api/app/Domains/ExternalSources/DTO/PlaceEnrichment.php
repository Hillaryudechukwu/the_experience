<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\DTO;

/**
 * Editorial content about a place, from an attributable source.
 *
 * Images arrive with their licence and creator because we are obliged to
 * display both, and because an image with no provenance is one we should not
 * be showing at all.
 */
final readonly class PlaceEnrichment
{
    public function __construct(
        public ?string $summary = null,
        public ?string $sourceName = null,
        public ?string $sourceUrl = null,
        public ?string $imageUrl = null,
        public ?string $imageLicence = null,
        public ?string $imageLicenceUrl = null,
        public ?string $imageCreator = null,
        public ?string $imageSourceUrl = null,
        public array $meta = [],
    ) {}

    public function hasImage(): bool
    {
        return $this->imageUrl !== null && $this->imageLicence !== null;
    }

    public function imageAttribution(): ?array
    {
        if (! $this->hasImage()) {
            return null;
        }

        return [
            'creator' => $this->imageCreator,
            'licence' => $this->imageLicence,
            'licence_url' => $this->imageLicenceUrl,
            'source_url' => $this->imageSourceUrl,
        ];
    }
}
