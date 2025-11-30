<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Log extends Model
{
    protected $fillable = ['strIdempotencyKey', 'strRequest', 'strResponseBody', 'intResponseCode'];

    protected $casts = [
        'strResponseBody' => 'array',
    ];
}
