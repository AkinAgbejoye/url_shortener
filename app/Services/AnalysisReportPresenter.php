<?php

namespace App\Services;

use App\Models\AnalysisJob;

class AnalysisReportPresenter
{
    /** @return array<string, mixed> */
    public function present(AnalysisJob $job): array
    {
        $job->loadMissing(['report', 'observations.pageFetch']);
        $report = $job->report;
        if ($report === null) {
            return [];
        }

        $evidence = [];
        $observations = [];
        foreach ($job->observations->sortBy('id') as $observation) {
            $evidence[] = [
                'id' => $observation->evidence_public_id,
                'source_url' => $observation->pageFetch->source_url,
                'captured_at' => $observation->observed_at->toISOString(),
                'excerpt' => $observation->excerpt,
            ];
            $observations[] = [
                'id' => $observation->public_id,
                'statement' => $observation->statement,
                'evidence_ids' => [$observation->evidence_public_id],
            ];
        }

        $userContext = [];
        foreach (array_values($job->user_context ?? []) as $index => $statement) {
            if (is_string($statement)) {
                $userContext[] = [
                    'id' => 'ctx_'.substr(hash('sha256', $job->public_id.'|'.$index), 0, 20),
                    'statement' => $statement,
                ];
            }
        }

        return [
            'analysis_id' => $job->public_id,
            'schema_version' => $report->schema_version,
            'generated_at' => $report->generated_at->toISOString(),
            'evidence' => $evidence,
            'observations' => $observations,
            'inferences' => [],
            'assumptions' => [],
            'user_supplied_context' => $userContext,
        ];
    }
}
