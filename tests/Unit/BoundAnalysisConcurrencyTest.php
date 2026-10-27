<?php

namespace Tests\Unit;

use App\Jobs\Middleware\BoundAnalysisConcurrency;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BoundAnalysisConcurrencyTest extends TestCase
{
    public function test_jobs_are_released_when_all_global_slots_are_occupied(): void
    {
        config(['website_intelligence.budgets.max_concurrent_jobs' => 2]);
        Cache::flush();
        $first = Cache::lock('website-intelligence:acquisition-slot:0', 30);
        $second = Cache::lock('website-intelligence:acquisition-slot:1', 30);
        $this->assertTrue($first->get());
        $this->assertTrue($second->get());
        $job = new class
        {
            public int $analysisJobId = 1;

            public int $releasedFor = 0;

            public function release(int $seconds): void
            {
                $this->releasedFor = $seconds;
            }
        };
        $ran = false;

        (new BoundAnalysisConcurrency)->handle($job, function () use (&$ran): void {
            $ran = true;
        });

        $this->assertFalse($ran);
        $this->assertSame(1, $job->releasedFor);
        $first->release();
        $second->release();
    }
}
