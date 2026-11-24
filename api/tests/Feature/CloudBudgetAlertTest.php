<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Services\GooglePubSubVerifier;
use App\Domains\Ops\Mail\BudgetAlertMail;
use App\Domains\Ops\Services\CloudBudgetAlertHandler;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class CloudBudgetAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'experience.ops.alert_emails' => ['ops@synteric.co.uk'],
            'experience.ops.budget_remaining_alert_fraction' => 0.10,
            'experience.ops.google_budget.pubsub_audience' => 'https://example.test/budget',
            'experience.ops.google_budget.pubsub_service_account' => 'budget-push@example.iam.gserviceaccount.com',
        ]);
    }

    public function test_a_verified_budget_push_emails_when_remaining_is_at_or_below_ten_percent(): void
    {
        Mail::fake();
        $this->acceptPubSub();

        $this->postJson('/api/ops/google/budget-alerts', [
            'message' => [
                'data' => base64_encode(json_encode([
                    'budgetDisplayName' => 'Places monthly',
                    'alertThresholdExceeded' => 0.9,
                    'costAmount' => 90.0,
                    'budgetAmount' => 100.0,
                    'currencyCode' => 'GBP',
                    'costIntervalStart' => '2026-11-01T00:00:00Z',
                ], JSON_THROW_ON_ERROR)),
            ],
        ])->assertOk()->assertJsonPath('code', 'notified');

        Mail::assertQueued(BudgetAlertMail::class, function (BudgetAlertMail $mail) {
            return $mail->hasTo('ops@synteric.co.uk')
                && $mail->alert['budget'] === 'Places monthly'
                && $mail->alert['remaining_pct'] === 10.0;
        });
    }

    public function test_a_lower_threshold_is_acknowledged_without_email(): void
    {
        Mail::fake();
        $this->acceptPubSub();

        $this->postJson('/api/ops/google/budget-alerts', [
            'message' => [
                'data' => base64_encode(json_encode([
                    'budgetDisplayName' => 'Places monthly',
                    'alertThresholdExceeded' => 0.5,
                    'costAmount' => 50.0,
                    'budgetAmount' => 100.0,
                    'currencyCode' => 'GBP',
                ], JSON_THROW_ON_ERROR)),
            ],
        ])->assertOk()->assertJsonPath('code', 'below_threshold');

        Mail::assertNothingQueued();
    }

    public function test_duplicate_pushes_for_the_same_threshold_do_not_re_email(): void
    {
        Mail::fake();
        $this->mock(GooglePubSubVerifier::class, function ($mock) {
            $mock->shouldReceive('verify')->twice()->andReturn(true);
        });

        $body = [
            'message' => [
                'data' => base64_encode(json_encode([
                    'budgetDisplayName' => 'Places monthly',
                    'alertThresholdExceeded' => 0.95,
                    'costAmount' => 95.0,
                    'budgetAmount' => 100.0,
                    'currencyCode' => 'GBP',
                    'costIntervalStart' => '2026-11-01T00:00:00Z',
                ], JSON_THROW_ON_ERROR)),
            ],
        ];

        $this->postJson('/api/ops/google/budget-alerts', $body)
            ->assertOk()
            ->assertJsonPath('code', 'notified');

        $this->postJson('/api/ops/google/budget-alerts', $body)
            ->assertOk()
            ->assertJsonPath('code', 'duplicate');

        Mail::assertQueued(BudgetAlertMail::class, 1);
    }

    public function test_a_failed_mail_queue_releases_dedupe_so_a_retry_can_notify(): void
    {
        $payload = [
            'budgetDisplayName' => 'Places monthly',
            'alertThresholdExceeded' => 0.92,
            'costAmount' => 92.0,
            'budgetAmount' => 100.0,
            'currencyCode' => 'GBP',
            'costIntervalStart' => '2026-11-01T00:00:00Z',
        ];

        $dedupeKey = 'ops:budget_alert:'.sha1('Places monthly|2026-11-01T00:00:00Z|0.92');

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('queue unavailable'));

        try {
            app(CloudBudgetAlertHandler::class)->handle($payload);
            $this->fail('Expected the mail queue failure to surface.');
        } catch (\RuntimeException $e) {
            $this->assertSame('queue unavailable', $e->getMessage());
        }

        $this->assertFalse(Cache::has($dedupeKey));

        Mail::clearResolvedInstances();
        $this->app->forgetInstance('mail.manager');
        Mail::fake();

        $this->assertSame('notified', app(CloudBudgetAlertHandler::class)->handle($payload));
        Mail::assertQueued(BudgetAlertMail::class, 1);
    }

    public function test_an_unverified_push_is_refused(): void
    {
        Mail::fake();

        $this->mock(GooglePubSubVerifier::class, function ($mock) {
            $mock->shouldReceive('verify')->once()->andReturn(false);
        });

        $this->postJson('/api/ops/google/budget-alerts', [
            'message' => ['data' => base64_encode('{}')],
        ])->assertStatus(401)->assertJsonPath('code', 'unverified');

        Mail::assertNothingQueued();
    }

    public function test_admin_can_read_the_last_budget_alert(): void
    {
        Cache::put('ops:budget_alert:last', [
            'budget' => 'Places monthly',
            'remaining_pct' => 8.0,
            'spent_pct' => 92.0,
            'cost' => '92',
            'amount' => '100',
            'currency' => 'GBP',
            'notified_at' => '2026-11-22T12:00:00+00:00',
        ], now()->addDay());

        $admin = User::factory()->create();
        Sanctum::actingAs($admin, ['admin']);

        $this->getJson('/api/admin/budget-alerts')
            ->assertOk()
            ->assertJsonPath('data.last_alert.budget', 'Places monthly')
            ->assertJsonPath('data.remaining_alert_fraction', 0.10)
            ->assertJsonPath('data.recipients.0', 'ops@synteric.co.uk');
    }

    public function test_budget_alert_status_requires_admin(): void
    {
        $this->getJson('/api/admin/budget-alerts')->assertUnauthorized();

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['traveller']);

        $this->getJson('/api/admin/budget-alerts')->assertForbidden();
    }

    private function acceptPubSub(): void
    {
        $this->mock(GooglePubSubVerifier::class, function ($mock) {
            $mock->shouldReceive('verify')->once()->andReturn(true);
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
