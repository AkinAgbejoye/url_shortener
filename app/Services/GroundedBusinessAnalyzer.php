<?php

namespace App\Services;

use App\Contracts\BusinessAnalysisProvider;
use App\Models\AnalysisReport;
use Illuminate\Support\Str;
use Throwable;

class GroundedBusinessAnalyzer
{
    public function __construct(
        private readonly BusinessAnalysisProvider $provider,
        private readonly RulesOnlyBusinessAnalysisProvider $fallback,
    ) {}

    public function analyze(AnalysisReport $report): void
    {
        $job = $report->analysisJob()->with('observations')->firstOrFail();
        $limit = min(200, max(1, (int) config('website_intelligence.analysis.max_provider_observations', 100)));
        $observations = $job->observations->take($limit)->map(fn ($observation): array => [
            'id' => $observation->evidence_public_id,
            'kind' => $this->bound($observation->kind, 40),
            'statement' => $this->bound($observation->statement, 500),
        ])->values()->all();
        $context = array_values(array_filter(array_map(
            fn (mixed $item): string => is_string($item) ? $this->bound($item, 500) : '',
            $job->user_context ?? [],
        )));

        try {
            $validated = $this->validate($this->provider->analyze($observations, $context), array_column($observations, 'id'));
        } catch (Throwable) {
            $validated = [];
        }
        if ($validated === []) {
            $validated = $this->validate($this->fallback->analyze($observations, $context), array_column($observations, 'id'));
        }

        $report->findings()->delete();
        foreach (array_slice($validated, 0, 50) as $finding) {
            $prefix = $finding['epistemic_type'] === 'inference' ? 'inf_' : 'asm_';
            $report->findings()->create(['public_id' => $prefix.Str::ulid(), ...$finding]);
        }
    }

    /** @param list<array<string, mixed>> $candidates @param list<string> $knownEvidence @return list<array<string, mixed>> */
    private function validate(array $candidates, array $knownEvidence): array
    {
        $valid = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $type = $candidate['epistemic_type'] ?? null;
            $confidence = $candidate['confidence'] ?? null;
            $statement = $candidate['statement'] ?? null;
            $evidence = array_values(array_unique(array_filter((array) ($candidate['evidence_ids'] ?? []), 'is_string')));
            if (! in_array($type, ['inference', 'assumption'], true)
                || ! in_array($confidence, ['low', 'medium', 'high', 'unknown'], true)
                || ! is_string($statement) || trim($statement) === '' || strlen($statement) > 2000
                || array_diff($evidence, $knownEvidence) !== []
                || ($type === 'inference' && $evidence === [])) {
                continue;
            }
            $valid[] = [
                'epistemic_type' => $type,
                'category' => $this->bound(is_string($candidate['category'] ?? null) ? $candidate['category'] : 'general', 40),
                'statement' => $this->bound($statement, 2000),
                'confidence' => $confidence,
                'evidence_ids' => array_slice($evidence, 0, 10),
                'details' => is_array($candidate['details'] ?? null) ? $candidate['details'] : null,
            ];
        }

        return $valid;
    }

    private function bound(string $value, int $max): string
    {
        return mb_strcut(trim(strip_tags($value)), 0, $max, 'UTF-8');
    }
}
