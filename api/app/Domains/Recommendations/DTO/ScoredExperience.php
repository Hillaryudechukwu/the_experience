<?php

declare(strict_types=1);

namespace App\Domains\Recommendations\DTO;

final readonly class ScoredExperience
{
    /** @param list<ComponentScore> $components */
    public function __construct(
        public ExperienceCandidate $candidate,
        public int $score,
        public array $components,
        public array $contributions,
    ) {}

    /** @return list<Reason> */
    public function reasons(?string $direction = null): array
    {
        $reasons = [];
        foreach ($this->components as $component) {
            foreach ($component->reasons as $reason) {
                if ($direction === null || $reason->direction === $direction) {
                    $reasons[] = $reason;
                }
            }
        }

        return $reasons;
    }

    public function explanation(): array
    {
        return [
            'score' => $this->score,
            'positive' => array_map(fn (Reason $r) => $r->message, $this->reasons('positive')),
            'negative' => array_map(fn (Reason $r) => $r->message, $this->reasons('negative')),
            'components' => array_map(fn (ComponentScore $c) => $c->toArray(), $this->components),
            'contributions' => $this->contributions,
        ];
    }
}
