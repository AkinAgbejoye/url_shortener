<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModerationDomainBlock extends Model
{
    protected $fillable = ['domain_hash', 'category', 'created_by', 'blocked_at', 'released_at'];

    protected $hidden = ['domain_hash'];

    protected function casts(): array
    {
        return ['blocked_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }
}
