<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Places\Services\OpeningHours;
use App\Domains\Shared\ValueObjects\TimeWindow;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class OpeningHoursTest extends TestCase
{
    public function test_unknown_hours_are_never_reported_as_open(): void
    {
        $hours = new OpeningHours(null);

        $this->assertFalse($hours->isKnown());
        $this->assertNull($hours->isOpenAt(CarbonImmutable::parse('2026-09-18 12:00')));
        $this->assertNull($hours->usableMinutes(new TimeWindow(
            CarbonImmutable::parse('2026-09-18 12:00'),
            CarbonImmutable::parse('2026-09-18 14:00'),
        )));
    }

    public function test_a_closed_day_is_respected(): void
    {
        $hours = new OpeningHours(['mon' => [], 'fri' => [['09:00', '17:00']]]);

        $this->assertFalse($hours->isOpenAt(CarbonImmutable::parse('2026-09-21 12:00')));  // Monday
        $this->assertTrue($hours->isOpenAt(CarbonImmutable::parse('2026-09-18 12:00')));   // Friday
    }

    public function test_a_date_exception_overrides_the_weekly_pattern(): void
    {
        $hours = new OpeningHours([
            'fri' => [['09:00', '17:00']],
            'exceptions' => ['2026-09-18' => []],
        ]);

        $this->assertFalse($hours->isOpenAt(CarbonImmutable::parse('2026-09-18 12:00')));
    }

    public function test_usable_minutes_are_the_overlap_with_the_travellers_window(): void
    {
        $hours = new OpeningHours(['fri' => [['09:00', '13:00']]]);

        $usable = $hours->usableMinutes(new TimeWindow(
            CarbonImmutable::parse('2026-09-18 12:00'),
            CarbonImmutable::parse('2026-09-18 16:00'),
        ));

        $this->assertSame(60, $usable);
    }

    public function test_hours_that_run_past_midnight_are_handled(): void
    {
        $hours = new OpeningHours(['fri' => [['20:00', '02:00']]]);

        $this->assertTrue($hours->isOpenAt(CarbonImmutable::parse('2026-09-18 23:30')));
        $this->assertTrue($hours->isOpenAt(CarbonImmutable::parse('2026-09-19 01:00')));
        $this->assertFalse($hours->isOpenAt(CarbonImmutable::parse('2026-09-19 03:00')));
    }
}
