<?php

namespace App\Enums;

enum AnalysisState: string
{
    case Queued = 'queued';
    case Fetching = 'fetching';
    case Analyzing = 'analyzing';
    case Completed = 'completed';
    case PartiallyCompleted = 'partially_completed';
    case Blocked = 'blocked';
    case CancelRequested = 'cancel_requested';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Failed = 'failed';

    public function active(): bool
    {
        return in_array($this, [self::Queued, self::Fetching, self::Analyzing, self::CancelRequested], true);
    }
}
