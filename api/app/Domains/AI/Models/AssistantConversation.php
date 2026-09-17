<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Domains\Shared\Concerns\BelongsToActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssistantConversation extends Model
{
    use BelongsToActor, HasUuids;

    protected $guarded = [];

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class)->orderBy('created_at');
    }
}
