<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Enums;

enum DestinationCoverageStatus: string
{
    case Discovered = 'discovered';
    case Queued = 'queued';
    case Importing = 'importing';
    case Ready = 'ready';
    case Limited = 'limited';
    case Failed = 'failed';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Discovered => [self::Queued, self::Failed],
            self::Queued => [self::Importing, self::Failed],
            self::Importing => [self::Ready, self::Limited, self::Failed],
            self::Limited => [self::Queued, self::Importing, self::Ready, self::Failed],
            self::Failed => [self::Queued, self::Limited, self::Ready],
            self::Ready => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return $this === $next || in_array($next, $this->allowedTransitions(), true);
    }

    public function isUsable(): bool
    {
        return in_array($this, [self::Ready, self::Limited], true);
    }
}
