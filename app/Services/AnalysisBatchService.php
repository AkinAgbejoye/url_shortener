<?php

namespace App\Services;

use App\Jobs\ProcessAnalysisBatch;
use App\Models\AnalysisBatch;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class AnalysisBatchService
{
    public function __construct(private readonly AnalysisManifestParser $parser) {}

    public function preview(string $manifest, string $format): array
    {
        $rows = $this->parser->parse($manifest, $format);

        return ['rows' => array_map(fn (array $row): array => ['row_number' => $row['row_number'], 'valid' => $row['valid'], 'errors' => $row['errors']], $rows), 'valid_rows' => count(array_filter($rows, fn (array $row): bool => $row['valid'])), 'invalid_rows' => count(array_filter($rows, fn (array $row): bool => ! $row['valid']))];
    }

    public function create(User $owner, string $manifest, string $format): AnalysisBatch
    {
        $rows = $this->parser->parse($manifest, $format);
        if ($rows === [] || array_filter($rows, fn (array $row): bool => ! $row['valid']) !== []) {
            throw new RuntimeException('manifest_validation_failed');
        }
        $active = AnalysisBatch::query()->ownedBy($owner)->whereIn('state', ['queued', 'running'])->count();
        if ($active >= max(1, (int) config('website_intelligence.bulk.max_active_per_account', 1))) {
            throw new RuntimeException('batch_quota_full');
        }
        $batch = AnalysisBatch::create([
            'owner_id' => $owner->id, 'public_id' => 'batch_'.Str::ulid(), 'state' => 'queued', 'format' => $format,
            'manifest_rows' => array_column($rows, 'data'), 'total_rows' => count($rows),
            'expires_at' => now()->addDays(max(1, (int) config('website_intelligence.budgets.retention_days', 30))),
        ]);
        ProcessAnalysisBatch::dispatch($batch->id)->onQueue('intelligence');

        return $batch;
    }
}
