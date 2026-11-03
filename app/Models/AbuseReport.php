<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbuseReport extends Model
{
    protected $fillable = [
        'public_id', 'reporter_id', 'category', 'reporter_email', 'contact_consent', 'description',
        'evidence_reference', 'subject_reference_hash', 'status', 'assigned_operator_id', 'resolved_at', 'retained_until',
    ];

    protected $hidden = ['reporter_email', 'description', 'evidence_reference', 'subject_reference_hash'];

    protected function casts(): array
    {
        return [
            'reporter_email' => 'encrypted', 'description' => 'encrypted', 'evidence_reference' => 'encrypted',
            'contact_consent' => 'boolean', 'resolved_at' => 'immutable_datetime', 'retained_until' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
