<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Services;

use DateTimeZone;
use NumberFormatter;

class DestinationMetadataResolver
{
    public function timezone(float $lat, float $lng): string
    {
        $nearest = 'UTC';
        $nearestDistance = INF;

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $location = (new DateTimeZone($identifier))->getLocation();

            if ($location === false) {
                continue;
            }

            $distance = (($location['latitude'] - $lat) ** 2)
                + ((($location['longitude'] - $lng) * cos(deg2rad($lat))) ** 2);

            if ($distance < $nearestDistance) {
                $nearest = $identifier;
                $nearestDistance = $distance;
            }
        }

        return $nearest;
    }

    public function currency(string $countryCode): string
    {
        $formatter = new NumberFormatter('en_'.mb_strtoupper($countryCode), NumberFormatter::CURRENCY);
        $currency = $formatter->getTextAttribute(NumberFormatter::CURRENCY_CODE);

        return is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';
    }
}
