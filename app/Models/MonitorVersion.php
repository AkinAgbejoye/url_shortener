<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorVersion extends Model
{
    protected $fillable = ['monitor_schedule_id', 'monitor_run_id', 'public_id', 'sequence', 'source_snapshot', 'evidence_references', 'parser_version', 'model_version', 'captured_at'];

    protected function casts(): array
    {
        return ['source_snapshot' => 'array', 'evidence_references' => 'array', 'captured_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<MonitorSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MonitorSchedule::class, 'monitor_schedule_id');
    }
}
