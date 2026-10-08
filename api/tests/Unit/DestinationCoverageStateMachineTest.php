<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Destinations\Enums\DestinationCoverageStatus;
use PHPUnit\Framework\TestCase;

class DestinationCoverageStateMachineTest extends TestCase
{
    public function test_a_discovered_destination_can_progress_to_importing_and_ready(): void
    {
        $this->assertTrue(DestinationCoverageStatus::Discovered->canTransitionTo(DestinationCoverageStatus::Queued));
        $this->assertTrue(DestinationCoverageStatus::Queued->canTransitionTo(DestinationCoverageStatus::Importing));
        $this->assertTrue(DestinationCoverageStatus::Importing->canTransitionTo(DestinationCoverageStatus::Ready));
        $this->assertTrue(DestinationCoverageStatus::Ready->isUsable());
    }

    public function test_a_ready_destination_cannot_be_demoted_by_a_refresh_failure(): void
    {
        $this->assertFalse(DestinationCoverageStatus::Ready->canTransitionTo(DestinationCoverageStatus::Importing));
        $this->assertFalse(DestinationCoverageStatus::Ready->canTransitionTo(DestinationCoverageStatus::Failed));
    }

    public function test_a_failed_or_limited_destination_can_be_retried(): void
    {
        $this->assertTrue(DestinationCoverageStatus::Failed->canTransitionTo(DestinationCoverageStatus::Queued));
        $this->assertTrue(DestinationCoverageStatus::Limited->canTransitionTo(DestinationCoverageStatus::Queued));
        $this->assertTrue(DestinationCoverageStatus::Limited->isUsable());
        $this->assertFalse(DestinationCoverageStatus::Failed->isUsable());
    }
}
