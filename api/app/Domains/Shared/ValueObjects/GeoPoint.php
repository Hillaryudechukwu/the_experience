<?php

declare(strict_types=1);

namespace App\Domains\Shared\ValueObjects;

use InvalidArgumentException;

final readonly class GeoPoint
{
    public function __construct(
        public float $lat,
        public float $lng,
    ) {
        if ($lat < -90 || $lat > 90) {
            throw new InvalidArgumentException("Latitude out of range: {$lat}");
        }
        if ($lng < -180 || $lng > 180) {
            throw new InvalidArgumentException("Longitude out of range: {$lng}");
        }
    }

    public static function fromArray(?array $data): ?self
    {
        if ($data === null || ! isset($data['lat'], $data['lng'])) {
            return null;
        }

        return new self((float) $data['lat'], (float) $data['lng']);
    }

    /** Great-circle distance in metres. */
    public function distanceTo(self $other): float
    {
        $earthRadius = 6371008.8;

        $lat1 = deg2rad($this->lat);
        $lat2 = deg2rad($other->lat);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad($other->lng - $this->lng);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * asin(min(1.0, sqrt($a)));
    }

    public function toArray(): array
    {
        return ['lat' => $this->lat, 'lng' => $this->lng];
    }
}
