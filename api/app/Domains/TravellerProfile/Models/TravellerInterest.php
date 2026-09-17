<?php

declare(strict_types=1);

namespace App\Domains\TravellerProfile\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TravellerInterest extends Model
{
    use HasUuids;

    protected $guarded = [];
}
