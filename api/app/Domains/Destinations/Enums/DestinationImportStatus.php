<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Enums;

enum DestinationImportStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Running], true);
    }
}
