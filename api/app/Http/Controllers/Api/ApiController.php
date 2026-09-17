<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Shared\ValueObjects\Actor;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    protected function actor(Request $request): Actor
    {
        return $request->attributes->get('actor') ?? new Actor;
    }
}
