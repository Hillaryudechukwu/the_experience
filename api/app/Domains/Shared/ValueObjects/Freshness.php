<?php

declare(strict_types=1);

namespace App\Domains\Shared\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * Provenance for a single fact.
 *
 * Every dynamic fact the API returns (price, opening hours, availability,
 * rating, weather) carries one of these so the client can show the traveller
 * where the fact came from and how old it is (spec s22.1, acceptance 57).
 */
final readonly class Freshness
{
    public const STATIC = 'static';
    public const SEMI_DYNAMIC = 'semi_dynamic';
    public const HIGHLY_DYNAMIC = 'highly_dynamic';

    public function __construct(
        public ?string $source,
        public ?CarbonImmutable $verifiedAt,
        public string $class = self::SEMI_DYNAMIC,
    ) {}

    public static function unknown(): self
    {
        return new self(null, null, self::SEMI_DYNAMIC);
    }

    public static function live(string $source, string $class = self::HIGHLY_DYNAMIC): self
    {
        return new self($source, CarbonImmutable::now(), $class);
    }

    public function ttlSeconds(): int
    {
        return (int) config("experience.freshness.{$this->class}.ttl_seconds", 86400);
    }

    public function ageSeconds(): ?int
    {
        $seconds = $this->verifiedAt?->diffInSeconds(CarbonImmutable::now());

        return $seconds === null ? null : (int) abs($seconds);
    }

    public function isStale(): bool
    {
        $age = $this->ageSeconds();

        return $age === null || $age > $this->ttlSeconds();
    }

    /** True only when the value is demonstrably current from a named source. */
    public function isTrusted(): bool
    {
        return $this->source !== null && ! $this->isStale();
    }

    public function label(): string
    {
        if ($this->source === null) {
            return 'Not verified';
        }
        if (! $this->isStale()) {
            return 'Checked ' . $this->verifiedAt->diffForHumans();
        }

        return 'Last checked ' . $this->verifiedAt->diffForHumans() . ' — may have changed';
    }

    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'verified_at' => $this->verifiedAt?->toIso8601String(),
            'class' => $this->class,
            'age_seconds' => $this->ageSeconds(),
            'is_stale' => $this->isStale(),
            'is_trusted' => $this->isTrusted(),
            'label' => $this->label(),
        ];
    }
}
