<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('journey_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->timestampsTz();
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('assistant_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);  // user|assistant
            $table->text('content');
            $table->jsonbDefault('tool_calls', '[]');
            $table->jsonbDefault('grounding', '{}');   // facts + sources + retrieved_at
            $table->jsonbDefault('suggestions', '[]');
            $table->string('driver', 24)->nullable();
            $table->timestampsTz();
        });

        Schema::create('notifications_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('guest_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 48);
            $table->string('title');
            $table->text('body');
            $table->jsonbDefault('payload', '{}');
            $table->timestampTz('scheduled_for');
            $table->timestampTz('sent_at')->nullable();
            $table->string('status', 24)->default('scheduled');
            $table->timestampsTz();
            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_outbox');
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};
