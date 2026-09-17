<?php

declare(strict_types=1);

namespace App\Domains\Destinations\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DestinationSignatureItem extends Model
{
    use HasUuids;

    protected $table = 'destination_signature_items';

    protected $guarded = [];
}
