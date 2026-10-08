<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Services;

use App\Domains\Destinations\Enums\DestinationCoverageStatus;
use App\Domains\Destinations\Models\Destination;
use DomainException;
use Illuminate\Support\Facades\Log;

class DestinationCoverageStateMachine
{
    public function transition(Destination $destination, DestinationCoverageStatus $to): Destination
    {
        $from = $destination->coverage_status;

        if ($from === $to) {
            return $destination;
        }

        if (! $from->canTransitionTo($to)) {
            throw new DomainException("Illegal destination coverage transition {$from->value} -> {$to->value}.");
        }

        $destination->coverage_status = $to;

        if ($to === DestinationCoverageStatus::Ready && $destination->ready_at === null) {
            $destination->ready_at = now();
        }

        $destination->save();

        Log::info('destination.coverage_transition', [
            'destination_id' => $destination->id,
            'from' => $from->value,
            'to' => $to->value,
        ]);

        return $destination;
    }
}
