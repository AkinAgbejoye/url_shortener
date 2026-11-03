<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ModerationAuditEvent extends Model
{
    protected $fillable = ['public_id', 'actor_id', 'action', 'subject_type', 'subject_hash', 'safe_metadata', 'occurred_at'];

    protected $hidden = ['subject_hash'];

    protected function casts(): array
    {
        return ['safe_metadata' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Moderation audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Moderation audit events are append-only.'));
    }
}
