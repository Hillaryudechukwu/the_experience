<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Models;

use App\Domains\Shared\Concerns\BelongsToActor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DevicePushToken extends Model
{
    use BelongsToActor, HasUuids;

    protected $guarded = [];
}
