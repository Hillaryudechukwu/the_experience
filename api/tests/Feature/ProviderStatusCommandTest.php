<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RecordQueueHeartbeat;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Operators need a no-secrets view of which suppliers are ready before a
 * staging smoke. The command must never print API keys, and its P4 gates
 * must stay green under the phpunit defaults (OSM, sandbox, rules, sync).
 */
class ProviderStatusCommandTest extends TestCase
{
    public function test_it_reports_provider_status_as_json_without_secrets(): void
    {
        $exit = Artisan::call('experience:provider-status', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertArrayHasKey('place_provider', $payload);
        $this->assertArrayHasKey('providers', $payload);
        $this->assertArrayHasKey('p4_gates', $payload);
        $this->assertTrue($payload['providers']['sandbox']['configured'] ?? false);
        $this->assertTrue($payload['p4_gates']['google_or_osm_accepted']);
        $this->assertTrue($payload['p4_gates']['commercial_fulfilment']);
        $this->assertTrue($payload['p4_gates']['assistant_driver_set']);
        $this->assertTrue($payload['p4_gates']['queue_healthy']);

        $encoded = json_encode($payload);
        $this->assertStringNotContainsString('sk-', (string) $encoded);
        $this->assertStringNotContainsString('AIza', (string) $encoded);
    }

    public function test_durable_queue_without_heartbeat_fails_the_queue_gate(): void
    {
        config(['queue.default' => 'database']);
        Cache::forget('health:queue-worker-heartbeat');

        $exit = Artisan::call('experience:provider-status', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertFalse($payload['p4_gates']['queue_healthy']);
        $this->assertFalse($payload['queue_worker_alive']);
    }

    public function test_durable_queue_with_fresh_heartbeat_passes(): void
    {
        config(['queue.default' => 'database']);
        Cache::forget('health:queue-worker-heartbeat');
        (new RecordQueueHeartbeat)->handle();

        $exit = Artisan::call('experience:provider-status', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertTrue($payload['queue_worker_alive']);
        $this->assertTrue($payload['p4_gates']['queue_healthy']);
    }
}
