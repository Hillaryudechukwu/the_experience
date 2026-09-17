<?php

declare(strict_types=1);

namespace App\Domains\JourneyIntelligence\Services;

use Illuminate\Support\Str;

/**
 * Turns a free-text Journey Mission into structured soft goals (spec s3.3).
 *
 * Soft goals bias ranking. They can never override a hard constraint such as a
 * booking, a closing time, an accessibility need or an explicit exclusion — the
 * scorer only ever reads them through JourneyPurposeFit, and CandidateBuilder
 * has already removed anything infeasible before scoring starts.
 */
class MissionInterpreter
{
    /** goal => phrases that imply it */
    private const SIGNALS = [
        'romantic' => ['romantic', 'romance', 'anniversary', 'honeymoon', 'just the two of us', 'my wife', 'my husband', 'my partner'],
        'authentic_food' => ['food', 'eat', 'eating', 'restaurant', 'cuisine', 'dish', 'taste', 'culinary', 'market'],
        'local_life' => ['local', 'locals', 'authentic', 'real ', 'like a resident', 'off the beaten', 'non-touristy', 'not touristy'],
        'scenic' => ['view', 'views', 'scenic', 'sunset', 'skyline', 'panorama', 'beautiful'],
        'golden_hour' => ['sunset', 'sunrise', 'golden hour'],
        'restful' => ['relax', 'rest', 'slow', 'unwind', 'recharge', 'quiet', 'calm', 'decompress'],
        'reflective' => ['reflect', 'think', 'space to', 'alone', 'clear my head'],
        'interactive' => ['child', 'children', 'kid', 'kids', 'son', 'daughter', 'family'],
        'age_appropriate' => ['child', 'children', 'kid', 'kids', 'toddler', 'son', 'daughter'],
        'depth' => ['history', 'understand', 'learn', 'context', 'museum', 'culture', 'cultural'],
        'budget_first' => ['cheap', 'budget', 'affordable', 'free', 'not spend', 'save money'],
        'close_to_base' => ['meetings', 'meeting', 'work', 'conference', 'hotel', 'nearby', 'close by', 'short trip'],
        'high_value_short' => ['not much time', 'little time', 'short', 'quick', 'squeeze', 'layover', 'between'],
        'celebratory' => ['celebrate', 'celebrating', 'birthday', 'special occasion', 'treat'],
        'social' => ['friends', 'group', 'meet people', 'social'],
        'nature' => ['nature', 'park', 'green', 'outdoors', 'walk', 'hike', 'garden'],
        'short_queues' => ['queue', 'queues', 'crowd', 'crowded', 'busy', 'lines', 'waiting'],
        'memorable_dining' => ['dinner', 'special meal', 'nice restaurant', 'fine dining'],
        'neighbourhood_feel' => ['neighbourhood', 'neighborhood', 'area', 'live here', 'move here', 'moving'],
    ];

    /** Phrases that signal the traveller wants to avoid the obvious. */
    private const NEGATIONS = ['don\'t want', 'do not want', 'dont want', 'avoid', 'no more', 'not another', 'rather not', 'sick of'];

    /**
     * @return list<array{goal:string, weight:float, evidence:string}>
     */
    public function interpret(?string $mission, string $reason = 'other'): array
    {
        $goals = [];

        /* The reason-for-travel playbook contributes its own baseline goals. */
        foreach ((array) config("experience.playbooks.{$reason}.goals", []) as $goal) {
            $goals[$goal] = ['goal' => $goal, 'weight' => 0.5, 'evidence' => 'Implied by your reason for travelling'];
        }

        $text = mb_strtolower(trim((string) $mission));

        if ($text !== '') {
            foreach (self::SIGNALS as $goal => $phrases) {
                foreach ($phrases as $phrase) {
                    if (! Str::contains($text, $phrase)) {
                        continue;
                    }

                    $negated = $this->isNegated($text, $phrase);
                    $weight = $negated ? 0.15 : 0.9;

                    /* Keep the strongest reading of each goal. */
                    if (! isset($goals[$goal]) || $goals[$goal]['weight'] < $weight) {
                        $goals[$goal] = [
                            'goal' => $goal,
                            'weight' => $weight,
                            'evidence' => $negated
                                ? "You said you would rather avoid this ('{$phrase}')"
                                : "From what you wrote: '{$phrase}'",
                        ];
                    }
                    break;
                }
            }
        }

        $list = array_values($goals);
        usort($list, fn (array $a, array $b) => $b['weight'] <=> $a['weight']);

        return $list;
    }

    /** @return array<string,float> goal => weight, ready for the scorer. */
    public function weights(array $goals): array
    {
        $weights = [];
        foreach ($goals as $goal) {
            $weights[$goal['goal']] = (float) $goal['weight'];
        }

        return $weights;
    }

    private function isNegated(string $text, string $phrase): bool
    {
        $position = mb_strpos($text, $phrase);
        if ($position === false) {
            return false;
        }

        $preceding = mb_substr($text, max(0, $position - 40), min(40, $position));

        foreach (self::NEGATIONS as $negation) {
            if (Str::contains($preceding, $negation)) {
                return true;
            }
        }

        return false;
    }
}
