<?php

declare(strict_types=1);

namespace App\Domains\Experiences\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ExperienceCategory extends Model
{
    use HasUuids;

    protected $guarded = [];
}
