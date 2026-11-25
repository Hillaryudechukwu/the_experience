<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

/**
 * Shared Claude request headers. Workspace-scoped keys require
 * anthropic-workspace-id; organisation keys do not.
 *
 * @return array<string, string>
 */
final class AnthropicHeaders
{
    public static function forMessagesApi(): array
    {
        $headers = [
            'x-api-key' => (string) config('experience.assistant.api_key'),
            'anthropic-version' => '2023-06-01',
        ];

        $workspaceId = trim((string) config('experience.assistant.workspace_id', ''));
        if ($workspaceId !== '') {
            $headers['anthropic-workspace-id'] = $workspaceId;
        }

        return $headers;
    }
}
