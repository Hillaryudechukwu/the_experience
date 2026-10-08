<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Enums;

enum DestinationImportStage: string
{
    case Queued = 'queued';
    case DiscoveringPlaces = 'discovering_places';
    case ResolvingPlaces = 'resolving_places';
    case EnrichingContent = 'enriching_content';
    case EvaluatingReadiness = 'evaluating_readiness';
    case Ready = 'ready';
    case Limited = 'limited';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
