<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceptance 61: the AI guide cannot invent live price, inventory or opening hours.
 * Spec s15: the guide reasons over trusted services and explains itself.
 */
class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->token = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');
    }

    public function test_the_guide_answers_from_tool_results_and_says_which_tools_it_used(): void
    {
        $response = $this->postJson('/api/assistant/message', [
            'message' => 'I have two hours near Borough Market. What is worth doing?',
            'destination' => 'london',
            'lat' => 51.5055,
            'lng' => -0.0910,
        ], ['X-Guest-Token' => $this->token]);

        $response->assertOk();
        $this->assertTrue($response->json('data.message.grounded'));
        $this->assertContains('recommend_experiences', $response->json('data.message.tool_calls'));
        $this->assertNotEmpty($response->json('data.message.experiences'));
        $this->assertSame([], $response->json('data.message.removed_claims'));
    }

    public function test_every_fact_in_the_answer_is_stored_with_its_source(): void
    {
        $response = $this->postJson('/api/assistant/message', [
            'message' => 'What should I do right now?',
            'destination' => 'london',
        ], ['X-Guest-Token' => $this->token]);

        $conversationId = $response->json('data.conversation_id');

        $this->assertDatabaseHas('assistant_messages', ['assistant_conversation_id' => $conversationId, 'role' => 'user']);

        $stored = \App\Domains\AI\Models\AssistantMessage::where('role', 'assistant')->firstOrFail();

        $this->assertArrayHasKey('facts', $stored->grounding);
        $this->assertArrayHasKey('retrieved_at', $stored->grounding);
        $this->assertTrue($stored->grounding['grounded']);
        $this->assertSame('rules', $stored->driver);
    }

    public function test_practical_questions_are_answered_from_sourced_city_essentials(): void
    {
        $destinationId = $this->getJson('/api/destinations?q=london')->json('data.0.id');
        $journeyId = $this->postJson('/api/journeys', [
            'destination_id' => $destinationId,
            'reason' => 'business',
        ], ['X-Guest-Token' => $this->token])->json('data.id');

        $response = $this->postJson('/api/assistant/message', [
            'message' => 'How do I pay for the tube, and should I tip?',
            'journey_id' => $journeyId,
        ], ['X-Guest-Token' => $this->token]);

        $response->assertOk();
        $content = $response->json('data.message.content');

        $this->assertStringContainsString('Transport for London', $content);
        $this->assertStringContainsString('Tipping', $content);
        $this->assertContains('get_city_essentials', $response->json('data.message.tool_calls'));
    }

    public function test_the_conversation_can_be_read_back(): void
    {
        $conversationId = $this->postJson('/api/assistant/message', [
            'message' => 'Something for a rainy afternoon',
            'destination' => 'london',
        ], ['X-Guest-Token' => $this->token])->json('data.conversation_id');

        $history = $this->getJson("/api/assistant/conversations/{$conversationId}", ['X-Guest-Token' => $this->token]);

        $history->assertOk();
        $this->assertCount(2, $history->json('data.messages'));
        $this->assertSame('user', $history->json('data.messages.0.role'));
        $this->assertSame('assistant', $history->json('data.messages.1.role'));
    }

    public function test_another_traveller_cannot_read_the_conversation(): void
    {
        $conversationId = $this->postJson('/api/assistant/message', [
            'message' => 'Something for a rainy afternoon',
            'destination' => 'london',
        ], ['X-Guest-Token' => $this->token])->json('data.conversation_id');

        $otherToken = $this->getJson('/api/destinations')->headers->get('X-Guest-Token');

        $this->getJson("/api/assistant/conversations/{$conversationId}", ['X-Guest-Token' => $otherToken])
            ->assertStatus(404);
    }
}
