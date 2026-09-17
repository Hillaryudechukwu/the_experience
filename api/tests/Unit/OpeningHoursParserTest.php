<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\ExternalSources\Support\OpeningHoursParser;
use App\Domains\Places\Services\OpeningHours;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The OSM opening_hours grammar is large; this parser covers the unambiguous
 * subset and refuses the rest. The refusals matter as much as the successes:
 * a half-understood schedule sends someone across a city to a locked door.
 */
class OpeningHoursParserTest extends TestCase
{
    public function test_it_parses_a_simple_weekday_range(): void
    {
        $result = OpeningHoursParser::parse('Mo-Fr 09:00-17:00');

        $this->assertSame([['09:00', '17:00']], $result['schedule']['mon']);
        $this->assertSame([['09:00', '17:00']], $result['schedule']['fri']);
        $this->assertArrayNotHasKey('sat', $result['schedule'], 'Unmentioned days are unknown, not closed.');
    }

    public function test_it_parses_multiple_rules_including_explicit_closures(): void
    {
        $result = OpeningHoursParser::parse('Tu-Su 10:00-17:30; Mo off');

        $this->assertSame([['10:00', '17:30']], $result['schedule']['tue']);
        $this->assertSame([], $result['schedule']['mon'], 'An explicit "off" is closed, which is different from unknown.');
    }

    public function test_it_parses_split_hours(): void
    {
        $result = OpeningHoursParser::parse('Mo-Fr 09:00-12:00,13:00-17:00');

        $this->assertSame([['09:00', '12:00'], ['13:00', '17:00']], $result['schedule']['wed']);
    }

    public function test_it_parses_a_wrapping_day_range(): void
    {
        $result = OpeningHoursParser::parse('Sa-Mo 10:00-16:00');

        foreach (['sat', 'sun', 'mon'] as $day) {
            $this->assertArrayHasKey($day, $result['schedule']);
        }
        $this->assertArrayNotHasKey('wed', $result['schedule']);
    }

    public function test_it_handles_always_open(): void
    {
        $result = OpeningHoursParser::parse('24/7');

        $this->assertCount(7, $result['schedule']);
        $this->assertSame(1.0, $result['confidence']);
    }

    #[DataProvider('unreadable')]
    public function test_it_refuses_what_it_cannot_read_confidently(string $tag): void
    {
        $this->assertNull(OpeningHoursParser::parse($tag), "Should have refused: {$tag}");
    }

    public static function unreadable(): array
    {
        return [
            'public holidays' => ['Mo-Fr 09:00-17:00; PH off'],
            'seasonal' => ['Apr-Oct 09:00-18:00'],
            'annotated' => ['Mo-Fr 09:00-17:00 "ring the bell"'],
            'sunset relative' => ['Mo-Su sunrise-sunset'],
            'week numbers' => ['week 1-20 09:00-17:00'],
            'nonsense day' => ['Xx-Fr 09:00-17:00'],
            'malformed time' => ['Mo-Fr 9-5'],
            'empty' => [''],
        ];
    }

    public function test_a_refused_tag_is_never_rendered_as_open(): void
    {
        /* The end-to-end consequence of a refusal: unknown stays unknown all
           the way through to the thing that decides whether to recommend. */
        $hours = new OpeningHours(OpeningHoursParser::parse('Apr-Oct 09:00-18:00')['schedule'] ?? null);

        $this->assertFalse($hours->isKnown());
        $this->assertNull($hours->isOpenAt(CarbonImmutable::parse('2026-06-15 12:00')));
    }

    public function test_a_parsed_tag_drives_the_real_opening_hours_object(): void
    {
        $hours = new OpeningHours(OpeningHoursParser::parse('Mo-Fr 09:00-17:00; Sa-Su off')['schedule']);

        $this->assertTrue($hours->isOpenAt(CarbonImmutable::parse('2026-09-16 12:00')));   // Wednesday
        $this->assertFalse($hours->isOpenAt(CarbonImmutable::parse('2026-09-19 12:00')));  // Saturday
    }
}
