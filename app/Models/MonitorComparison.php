<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorComparison extends Model
{
    protected $fillable = ['monitor_schedule_id', 'previous_version_id', 'current_version_id', 'material', 'source_changes', 'version_drift', 'evidence_citations', 'notified_at'];

    protected function casts(): array
    {
        return ['material' => 'boolean', 'source_changes' => 'array', 'version_drift' => 'array', 'evidence_citations' => 'array', 'notified_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<MonitorVersion, $this> */
    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(MonitorVersion::class, 'previous_version_id');
    }

    /** @return BelongsTo<MonitorVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(MonitorVersion::class, 'current_version_id');
    }
}
