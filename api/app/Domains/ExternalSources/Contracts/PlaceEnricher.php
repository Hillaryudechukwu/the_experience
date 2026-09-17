<?php

declare(strict_types=1);

namespace App\Domains\ExternalSources\Contracts;

use App\Domains\ExternalSources\DTO\PlaceCandidate;
use App\Domains\ExternalSources\DTO\PlaceEnrichment;

interface PlaceEnricher
{
    public function key(): string;

    public function enrich(PlaceCandidate $candidate): ?PlaceEnrichment;
}
