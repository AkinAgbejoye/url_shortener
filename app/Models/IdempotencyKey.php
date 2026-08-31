<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $fillable = [
        'key',
        'request_hash',
        'response',
    ];

    protected $casts = [
        'response' => 'array',
    ];
}