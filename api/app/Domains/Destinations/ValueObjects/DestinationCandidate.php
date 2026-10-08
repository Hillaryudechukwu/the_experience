<?php

declare(strict_types=1);

namespace App\Domains\Destinations\ValueObjects;

final readonly class DestinationCandidate
{
    public function __construct(
        public string $provider,
        public string $externalId,
        public string $name,
        public ?string $region,
        public string $country,
        public string $countryCode,
        public float $lat,
        public float $lng,
        public string $kind,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'external_id' => $this->externalId,
            'name' => $this->name,
            'region' => $this->region,
            'country' => $this->country,
            'country_code' => $this->countryCode,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'kind' => $this->kind,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            provider: (string) $data['provider'],
            externalId: (string) $data['external_id'],
            name: (string) $data['name'],
            region: isset($data['region']) ? (string) $data['region'] : null,
            country: (string) $data['country'],
            countryCode: mb_strtoupper((string) $data['country_code']),
            lat: (float) $data['lat'],
            lng: (float) $data['lng'],
            kind: (string) $data['kind'],
        );
    }
}
