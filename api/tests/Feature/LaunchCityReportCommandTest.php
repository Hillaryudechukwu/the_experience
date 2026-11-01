<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class LaunchCityReportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_published_coverage_for_a_seeded_city(): void
    {
        $this->seed(DatabaseSeeder::class);

        $exit = Artisan::call('experience:launch-city-report', ['slug' => 'london', '--json' => true]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('london', $payload['slug']);
        $this->assertGreaterThan(0, $payload['published_experiences']);
        $this->assertArrayHasKey('with_photo_percent', $payload);
    }

    public function test_unknown_slug_fails(): void
    {
        $this->assertSame(1, Artisan::call('experience:launch-city-report', ['slug' => 'not-a-city']));
    }
}
