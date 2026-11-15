<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Bookings\Models\Booking;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The other half of account deletion's one exception.
 *
 * DeleteTravellerAccount keeps paid bookings as anonymous ledger lines because
 * tax and consumer-protection law has a claim on them. That claim runs out,
 * and the privacy policy and the deletion page both say the line goes when it
 * does — this is what makes that true.
 *
 * Only rows deletion marked as anonymised are touched. A booking that lost its
 * user some other way is not this command's to decide about.
 */
#[Signature('bookings:prune-anonymised')]
#[Description('Delete anonymised booking ledger lines older than the legal retention period')]
class PruneAnonymisedBookings extends Command
{
    public function handle(): int
    {
        $years = (int) config('experience.privacy.anonymised_booking_retention_years');
        $cutoff = now()->subYears($years);

        /* Child rows (items, payments, events) cascade with the booking. */
        $pruned = Booking::query()
            ->whereNull('user_id')
            ->whereNull('guest_session_id')
            ->whereNotNull('meta->anonymised_at')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$pruned} anonymised bookings older than {$years} years.");

        return self::SUCCESS;
    }
}
