<?php

declare(strict_types=1);

namespace App\Domains\Discovery\Services;

use App\Domains\Experiences\Models\Experience;
use App\Domains\Recommendations\DTO\ScoredExperience;

/**
 * The one sentence on Home: what to do, and where.
 *
 * Composed from the top card and a reason the scorer already stored. No model
 * call, and no number that is not already on the card.
 */
class PrimaryActionComposer
{
    public function compose(?ScoredExperience $top): ?array
    {
        if ($top === null) {
            return null;
        }

        $experience = $top->candidate->experience;
        $place = $experience->place;
        $why = $top->reasons('positive')[0]->message ?? null;
        $lat = $place?->lat;
        $lng = $place?->lng;

        return [
            'experience_id' => $experience->id,
            'title' => $experience->title,
            'action' => $this->action($experience, $why),
            'place_name' => $place->name ?? $experience->title,
            'neighbourhood' => $place?->neighbourhood?->name,
            'travel_minutes' => $top->candidate->travelMinutes(),
            'directions_url' => $lat === null || $lng === null
                ? null
                : sprintf('https://www.google.com/maps/dir/?api=1&destination=%s,%s', $lat, $lng),
            'why' => $why,
        ];
    }

    /** Keeps the hero and the first card on the same place after a search reorder. */
    public function fromCard(array $card): array
    {
        $why = $card['why'][0] ?? null;
        $title = (string) $card['title'];
        $lat = $card['location']['lat'] ?? null;
        $lng = $card['location']['lng'] ?? null;

        return [
            'experience_id' => $card['id'],
            'title' => $title,
            'action' => is_string($why) && $why !== ''
                ? 'Go here. '.$this->sentence($why)
                : "Go to {$title}.",
            'place_name' => $card['location']['name'] ?? $title,
            'neighbourhood' => $card['location']['neighbourhood'] ?? null,
            'travel_minutes' => $card['travel']['minutes'] ?? null,
            'directions_url' => $lat === null || $lng === null
                ? null
                : sprintf('https://www.google.com/maps/dir/?api=1&destination=%s,%s', $lat, $lng),
            'why' => $why,
        ];
    }

    private function action(Experience $experience, ?string $why): string
    {
        if ($why === null || $why === '') {
            return "Go to {$experience->title}.";
        }

        return $this->imperative($experience).' '.$this->sentence($why);
    }

    private function imperative(Experience $experience): string
    {
        $kind = (string) ($experience->place?->kind ?? '');
        $keys = $experience->relationLoaded('categories')
            ? $experience->categories->pluck('key')->all()
            : [];

        return match (true) {
            $kind === 'food' || in_array('food_experience', $keys, true) => 'Eat here.',
            $kind === 'park' || in_array('nature', $keys, true) => 'Walk here.',
            $kind === 'viewpoint' => 'See the view.',
            $kind === 'market' => 'Walk the market.',
            in_array($kind, ['museum', 'gallery', 'historic', 'worship'], true) => 'Go inside.',
            $kind === 'nightlife' => 'Go out here.',
            default => 'Go here.',
        };
    }

    private function sentence(string $why): string
    {
        $why = trim($why);

        return str_ends_with($why, '.') ? $why : $why.'.';
    }
}
