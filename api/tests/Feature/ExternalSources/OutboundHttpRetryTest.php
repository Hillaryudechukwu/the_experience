<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalSources;

use App\Domains\ExternalSources\Services\OutboundHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Only failures another attempt can fix are retried: asking again after a
 * 4xx answer only spends quota and time.
 */
class OutboundHttpRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    public function test_a_server_error_is_retried(): void
    {
        Http::fake(['example.test/*' => Http::sequence()->push([], 503)->push(['ok' => true])]);

        $response = app(OutboundHttp::class)->for('default')->get('https://example.test/thing');

        $this->assertTrue($response->successful());
        Http::assertSentCount(2);
    }

    public function test_a_rate_limit_is_retried(): void
    {
        Http::fake(['example.test/*' => Http::sequence()->push([], 429)->push(['ok' => true])]);

        $this->assertTrue(app(OutboundHttp::class)->for('default')->get('https://example.test/thing')->successful());
        Http::assertSentCount(2);
    }

    public function test_a_client_error_is_not_retried(): void
    {
        Http::fake(['example.test/*' => Http::response(['error' => 'bad'], 400)]);

        $response = app(OutboundHttp::class)->for('default')->get('https://example.test/thing');

        $this->assertSame(400, $response->status());
        Http::assertSentCount(1);
    }
}
