<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AssistantMessage extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tool_calls' => 'array', 'grounding' => 'array', 'suggestions' => 'array'];
    }
}
