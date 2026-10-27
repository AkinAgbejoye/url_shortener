<?php

namespace App\Jobs;

use App\Models\AnalysisJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeleteAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $analysisJobId) {}

    public function handle(): void
    {
        AnalysisJob::query()->whereKey($this->analysisJobId)->delete();
    }
}
