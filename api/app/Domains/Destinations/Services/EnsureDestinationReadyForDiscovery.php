<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Services;

use App\Domains\Destinations\Actions\QueueEmptyDestinationImport;
use App\Domains\Destinations\Enums\DestinationCoverageStatus;
use App\Domains\Destinations\Enums\DestinationImportStatus;
use App\Domains\Destinations\Models\Destination;
use App\Domains\Destinations\Models\DestinationImport;
use App\Domains\Journeys\Models\Journey;
use App\Domains\Shared\ValueObjects\Actor;
use Illuminate\Http\Exceptions\HttpResponseException;

class EnsureDestinationReadyForDiscovery
{
    public function __construct(
        private readonly QueueEmptyDestinationImport $fillEmptyCatalogue,
    ) {}

    /** @param array<string, mixed> $input */
    public function handle(Actor $actor, array $input): void
    {
        $destination = $this->resolve($actor, $input);

        if ($destination === null) {
            return;
        }

        if ($destination->coverage_status->isUsable()) {
            $import = $this->fillEmptyCatalogue->handle($destination, $actor);

            if ($import !== null) {
                $this->throwPreparing($destination->fresh() ?? $destination, $import);
            }

            return;
        }

        $import = $destination->imports()
            ->whereIn('status', [DestinationImportStatus::Queued->value, DestinationImportStatus::Running->value])
            ->latest()
            ->first();
        $preparing = in_array($destination->coverage_status, [
            DestinationCoverageStatus::Discovered,
            DestinationCoverageStatus::Queued,
            DestinationCoverageStatus::Importing,
        ], true);

        if ($preparing) {
            $this->throwPreparing($destination, $import);
        }

        throw new HttpResponseException(response()->json([
            'message' => 'This destination is not available for discovery yet.',
            'code' => 'destination_unavailable',
            'destination_id' => $destination->id,
            'coverage_status' => $destination->coverage_status->value,
            'import_id' => $import?->id,
            'retry_after_seconds' => null,
        ], 409));
    }

    private function throwPreparing(Destination $destination, ?DestinationImport $import): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'This destination is still being prepared.',
            'code' => 'destination_preparing',
            'destination_id' => $destination->id,
            'coverage_status' => $destination->coverage_status->value,
            'import_id' => $import?->id,
            'retry_after_seconds' => 3,
        ], 409, ['Retry-After' => '3']));
    }

    /** @param array<string, mixed> $input */
    private function resolve(Actor $actor, array $input): ?Destination
    {
        if (! empty($input['destination_id'])) {
            return Destination::find($input['destination_id']);
        }
        if (! empty($input['destination'])) {
            return Destination::where('slug', $input['destination'])->first();
        }

        $journey = ! empty($input['journey_id'])
            ? Journey::with('destination')->ownedBy($actor)->find($input['journey_id'])
            : Journey::with('destination')->ownedBy($actor)->where('status', 'active')->latest('updated_at')->first();

        return $journey?->destination;
    }
}
