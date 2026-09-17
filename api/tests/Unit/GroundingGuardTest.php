<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\AI\Services\GroundingGuard;
use Tests\TestCase;

/** Acceptance 61: the guide cannot invent live price, inventory or opening hours. */
class GroundingGuardTest extends TestCase
{
    public function test_a_price_that_came_from_a_tool_is_allowed_through(): void
    {
        $facts = ['results' => [['title' => 'Tower of London', 'price' => ['minor' => 3500, 'currency' => 'GBP']]]];

        $result = app(GroundingGuard::class)->verify('Tickets start at £35.', $facts);

        $this->assertTrue($result['grounded']);
        $this->assertSame('Tickets start at £35.', $result['text']);
    }

    public function test_an_invented_price_is_removed(): void
    {
        $facts = ['results' => [['title' => 'Tower of London', 'price' => ['minor' => 3500, 'currency' => 'GBP']]]];

        $result = app(GroundingGuard::class)->verify(
            'It is a great visit. Tickets are £12 on the door.',
            $facts,
        );

        $this->assertFalse($result['grounded']);
        $this->assertStringNotContainsString('£12', $result['text']);
        $this->assertStringContainsString('It is a great visit.', $result['text']);
        $this->assertContains('£12', $result['violations']);
    }

    public function test_an_invented_opening_time_is_removed(): void
    {
        $facts = ['experience' => ['dynamic' => ['opening_hours' => ['closes_at' => '2026-09-18T17:30:00+01:00']]]];

        $result = app(GroundingGuard::class)->verify('It closes at 21:00 tonight.', $facts);

        $this->assertFalse($result['grounded']);
        $this->assertStringNotContainsString('21:00', $result['text']);
    }

    public function test_a_supported_opening_time_survives(): void
    {
        $facts = ['experience' => ['dynamic' => ['opening_hours' => ['closes_at' => '2026-09-18T17:30:00+01:00']]]];

        $result = app(GroundingGuard::class)->verify('It closes at 17:30 today.', $facts);

        $this->assertTrue($result['grounded']);
    }

    public function test_when_everything_is_unsupported_the_guide_says_so_rather_than_going_silent(): void
    {
        $result = app(GroundingGuard::class)->verify('It costs £99 and closes at 23:00.', []);

        $this->assertFalse($result['grounded']);
        $this->assertNotEmpty($result['text']);
        $this->assertStringNotContainsString('£99', $result['text']);
    }
}
