<?php

namespace App\Services;

use App\Models\AnalysisJob;
use App\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalysisExportService
{
    public function __construct(private readonly AnalysisReportPresenter $presenter) {}

    /** @param list<string> $ids */
    public function stream(User $owner, array $ids, string $format): StreamedResponse
    {
        $ids = array_slice(array_values(array_unique($ids)), 0, 100);
        $query = AnalysisJob::query()->ownedBy($owner)->whereIn('public_id', $ids)->with(['report.findings', 'observations.pageFetch'])->orderBy('id');
        $callback = $format === 'csv' ? function () use ($query): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['analysis_id', 'state', 'origin', 'generated_at', 'evidence_count', 'observation_count'], ',', '"', '');
            foreach ($query->cursor() as $job) {
                $data = $job->report === null ? [] : $this->presenter->present($job);
                fputcsv($out, [$this->cell($job->public_id), $this->cell($job->state->value), $this->cell($job->submitted_origin), $this->cell($data['generated_at'] ?? ''), count($data['evidence'] ?? []), count($data['observations'] ?? [])], ',', '"', '');
            }
            fclose($out);
        } : function () use ($query): void {
            echo '[';
            $first = true;
            foreach ($query->cursor() as $job) {
                if (! $first) {
                    echo ',';
                } $first = false;
                echo json_encode(['analysis_id' => $job->public_id, 'state' => $job->state->value, 'submitted_origin' => $job->submitted_origin, 'report' => $job->report === null ? null : $this->presenter->present($job)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
            echo ']';
        };

        return response()->stream($callback, 200, ['Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json', 'Content-Disposition' => 'attachment; filename="analysis-export.'.$format.'"', 'Cache-Control' => 'private, no-store, max-age=0']);
    }

    private function cell(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }
}
