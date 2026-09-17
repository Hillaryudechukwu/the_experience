<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ExternalSources\Contracts\ExperienceProvider;
use App\Domains\ExternalSources\Contracts\ProviderCapability;
use App\Domains\ExternalSources\DTO\Availability;
use App\Domains\ExternalSources\DTO\BookingRequest;
use App\Domains\ExternalSources\DTO\BookingResult;
use App\Domains\ExternalSources\DTO\CancellationResult;
use App\Domains\ExternalSources\DTO\DateRange;
use App\Domains\ExternalSources\DTO\ExperienceProduct;
use App\Domains\ExternalSources\DTO\SearchCriteria;
use App\Domains\ExternalSources\Models\ProviderProduct;
use App\Domains\ExternalSources\Services\ProviderHealthMonitor;
use App\Domains\ExternalSources\Services\ProviderRegistry;
use App\Domains\Experiences\Models\Experience;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\TestCase;

/** Acceptance 59: provider failure does not break unrelated discovery content. */
class ProviderResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_failing_supplier_degrades_its_own_offer_not_the_whole_page(): void
    {
        $this->breakTheSandboxProvider();

        $experience = Experience::where('slug', 'london-tower-of-london')->firstOrFail();
        $response = $this->getJson("/api/experiences/{$experience->id}");

        $response->assertOk();

        /* Descriptive content and the score are untouched. */
        $this->assertNotEmpty($response->json('data.descriptive.why_it_matters'));
        $this->assertNotNull($response->json('data.experience_score'));

        /* The broken supplier is flagged; the working one still works. */
        $offers = collect($response->json('data.dynamic.offers'));
        $this->assertTrue($offers->firstWhere('provider', 'sandbox')['degraded']);
        $this->assertFalse($offers->firstWhere('provider', 'deeplink')['degraded']);
    }

    public function test_recommendations_still_work_when_every_ticket_supplier_is_down(): void
    {
        $this->breakTheSandboxProvider();

        $response = $this->postJson('/api/discovery/now', ['destination' => 'london']);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_repeated_failures_open_the_circuit_and_the_registry_stops_calling(): void
    {
        $monitor = app(ProviderHealthMonitor::class);
        $threshold = (int) config('experience.providers.circuit_breaker.failure_threshold');

        for ($i = 0; $i < $threshold; $i++) {
            $monitor->recordFailure('flaky', new RuntimeException('boom'));
        }

        $this->assertFalse($monitor->isAvailable('flaky'));
        $this->assertDatabaseHas('provider_health', ['provider' => 'flaky', 'status' => 'down']);

        $monitor->recordSuccess('flaky', 120);

        $this->assertTrue($monitor->isAvailable('flaky'));
    }

    public function test_an_unconfigured_provider_is_never_offered(): void
    {
        $status = app(ProviderRegistry::class)->status();

        $this->assertFalse($status['viator']['configured'], 'Viator has no API key in the test environment.');

        $usable = array_map(fn ($p) => $p->key(), app(ProviderRegistry::class)->usable());
        $this->assertNotContains('viator', $usable);
    }

    private function breakTheSandboxProvider(): void
    {
        $registry = app(ProviderRegistry::class);
        $registry->register(new class implements ExperienceProvider
        {
            public function key(): string
            {
                return 'sandbox';
            }

            public function capabilities(): array
            {
                return [ProviderCapability::CONTENT, ProviderCapability::LIVE_AVAILABILITY, ProviderCapability::NATIVE_BOOKING];
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function search(SearchCriteria $criteria): Collection
            {
                throw new RuntimeException('Supplier timeout');
            }

            public function getProduct(string $providerId): ?ExperienceProduct
            {
                throw new RuntimeException('Supplier timeout');
            }

            public function availability(string $providerId, DateRange $dates): Availability
            {
                throw new RuntimeException('Supplier timeout');
            }

            public function createBooking(BookingRequest $request): BookingResult
            {
                throw new RuntimeException('Supplier timeout');
            }

            public function cancelBooking(string $providerBookingId): CancellationResult
            {
                throw new RuntimeException('Supplier timeout');
            }
        });

        $this->assertNotNull(ProviderProduct::where('provider', 'sandbox')->first());
    }
}
