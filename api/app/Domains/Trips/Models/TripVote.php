<?php

declare(strict_types=1);

namespace App\Domains\Trips\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TripVote extends Model
{
    use HasUuids;

    public const MUST_DO = 'must_do';
    public const INTERESTED = 'interested';
    public const SKIP = 'skip';

    protected $guarded = [];
}
