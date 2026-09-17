<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    use HasUuids;

    protected $guarded = [];
}
