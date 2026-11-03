<?php

namespace App\Jobs;

use App\Models\SiteImport;
use App\Services\AuthorizedSiteImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ImportVerifiedSite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $importId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('site-import:'.$this->importId))->expireAfter(120)];
    }

    public function handle(AuthorizedSiteImporter $importer): void
    {
        $import = SiteImport::query()->find($this->importId);
        if ($import === null || $import->state !== 'queued') {
            return;
        }
        if ($import->cancel_requested_at !== null) {
            $import->forceFill(['state' => 'cancelled', 'completed_at' => now()])->save();

            return;
        }
        $import->forceFill(['state' => 'importing'])->save();
        try {
            $result = $importer->import($import);
            $import->forceFill([
                'state' => $result['summary']['rejected_assets'] > 0 ? 'partially_ready' : 'preview_ready',
                'sanitized_html' => $result['html'],
                'editable_representation' => $result['editable'],
                'sanitation_summary' => $result['summary'],
                'provenance' => $result['provenance'],
                'safe_failure_category' => null,
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $cancelled = $exception->getMessage() === 'cancelled';
            $category = in_array($exception->getMessage(), ['authorization_expired', 'runtime_limit', 'total_byte_limit'], true)
                ? $exception->getMessage() : 'sanitizer_or_asset_failure';
            $import->forceFill(['state' => $cancelled ? 'cancelled' : 'failed', 'safe_failure_category' => $cancelled ? null : $category, 'completed_at' => now()])->save();
        }
    }
}
