<?php

declare(strict_types=1);

namespace App\Domains\Discovery\Services;

use App\Domains\ExternalSources\DTO\WeatherSnapshot;

/**
 * What to wear for this forecast.
 *
 * A pure function of the snapshot already fetched. It does not mention wind,
 * because the forecast does not carry wind, and it does not invent a garment
 * when there is no snapshot.
 */
class ClothingAdvisor
{
    /** @var list<string> */
    private const WET = ['rain', 'heavy_rain', 'storm', 'snow', 'sleet'];

    /**
     * @return array{headline: string, items: list<string>}|null
     */
    public function advise(?WeatherSnapshot $weather): ?array
    {
        if ($weather === null || ! config('experience.weather.clothing_enabled', true)) {
            return null;
        }

        $temp = $weather->temperatureC;
        $condition = $weather->condition;
        $poor = in_array($condition, self::WET, true);

        if (in_array($condition, ['snow', 'sleet'], true) || $temp < 5) {
            $headline = 'Cold. Cover up.';
            $items = ['warm coat', 'hat', 'closed shoes'];
            if ($poor || $weather->precipitationProbability >= 40) {
                $items[] = 'waterproof jacket';
                $headline = 'Take a waterproof layer. It is cold and wet.';
            }
        } elseif ($temp <= 12) {
            $headline = 'Cool. A real layer.';
            $items = ['jacket', 'layer you can take off indoors', 'closed shoes'];
        } elseif ($temp <= 18) {
            $headline = 'Mild. Layers.';
            $items = ['light jacket', 'comfortable walking shoes'];
        } elseif ($temp <= 25 && ! $poor) {
            $headline = 'Warm. Light clothes, and a layer for the evening.';
            $items = ['light clothes', 'a layer for the evening'];
        } elseif ($temp > 25 && ! $poor) {
            $headline = 'Hot. Breathable clothes and sun protection.';
            $items = ['breathable clothes', 'sun protection', 'water'];
        } else {
            $headline = 'Take a waterproof layer. It is wet.';
            $layer = $temp <= 12 ? 'jacket' : ($temp <= 18 ? 'light jacket' : 'a layer for the evening');
            $items = ['waterproof jacket', 'shoes that can get wet', $layer];
        }

        if ($this->rainSoon($weather) && ! in_array('waterproof jacket', $items, true)) {
            $items[] = 'waterproof jacket';
            if (! str_contains(strtolower($headline), 'waterproof')) {
                $headline = rtrim($headline, '.').'. Take a waterproof layer. Rain is likely soon.';
            }
        }

        return [
            'headline' => $headline,
            'items' => array_values(array_unique($items)),
        ];
    }

    private function rainSoon(WeatherSnapshot $weather): bool
    {
        if ($weather->precipitationProbability >= 50) {
            return true;
        }

        foreach (array_slice($weather->hourly, 0, 4) as $point) {
            if (($point['precip_probability'] ?? 0) >= 50) {
                return true;
            }
            if (in_array($point['condition'] ?? '', self::WET, true)) {
                return true;
            }
        }

        return false;
    }
}
