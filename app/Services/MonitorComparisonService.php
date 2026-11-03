<?php

namespace App\Services;

use App\Models\MonitorComparison;
use App\Models\MonitorVersion;

class MonitorComparisonService
{
    public function compare(MonitorVersion $previous, MonitorVersion $current, int $minimumChangedCategories): MonitorComparison
    {
        $sourceChanges = [];
        $coreChanged = false;
        foreach (['content', 'pricing', 'calls_to_action', 'structured_data'] as $category) {
            if (($previous->source_snapshot[$category]['hash'] ?? null) !== ($current->source_snapshot[$category]['hash'] ?? null)) {
                $coreChanged = true;
                $sourceChanges[] = $this->change($category, $previous, $current);
            }
        }
        if ($coreChanged) {
            foreach (['business_assumptions', 'recommendations'] as $category) {
                if (($previous->source_snapshot[$category]['hash'] ?? null) !== ($current->source_snapshot[$category]['hash'] ?? null)) {
                    $sourceChanges[] = $this->change($category, $previous, $current);
                }
            }
        }
        $drift = [];
        if ($previous->parser_version !== $current->parser_version) {
            $drift[] = ['kind' => 'parser_version', 'from' => $previous->parser_version, 'to' => $current->parser_version, 'source_fact' => false];
        }
        if ($previous->model_version !== $current->model_version) {
            $drift[] = ['kind' => 'model_version', 'from' => $previous->model_version, 'to' => $current->model_version, 'source_fact' => false];
        }

        return MonitorComparison::create([
            'monitor_schedule_id' => $current->monitor_schedule_id,
            'previous_version_id' => $previous->id,
            'current_version_id' => $current->id,
            'material' => count($sourceChanges) >= max(1, $minimumChangedCategories),
            'source_changes' => $sourceChanges,
            'version_drift' => $drift,
            'evidence_citations' => [
                ['version_id' => $previous->public_id, 'evidence' => $previous->evidence_references],
                ['version_id' => $current->public_id, 'evidence' => $current->evidence_references],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function change(string $category, MonitorVersion $previous, MonitorVersion $current): array
    {
        return [
            'category' => $category,
            'before' => ['version_id' => $previous->public_id, 'evidence_hash' => $previous->source_snapshot[$category]['hash']],
            'after' => ['version_id' => $current->public_id, 'evidence_hash' => $current->source_snapshot[$category]['hash']],
        ];
    }
}
