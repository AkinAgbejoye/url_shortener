<?php

namespace App\Services;

use App\Models\AnalysisJob;
use App\Models\AnalysisObservation;
use App\Models\AnalysisPageFetch;
use App\Models\AnalysisReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnalysisReportBuilder
{
    /**
     * @param  array{source_url: string, extraction: array<string, mixed>, provenance: array<string, mixed>}  $result
     * @return array{report: AnalysisReport, observation_count: int}
     */
    public function build(AnalysisJob $job, array $result): array
    {
        return DB::transaction(function () use ($job, $result): array {
            $job->observations()->delete();
            $job->pageFetches()->delete();
            $job->report()->delete();

            $provenance = $result['provenance'];
            $fetch = AnalysisPageFetch::create([
                'analysis_job_id' => $job->id,
                'public_id' => 'fetch_'.Str::ulid(),
                'source_url' => $result['source_url'],
                'status_code' => (int) ($provenance['status'] ?? 0),
                'content_type' => (string) ($provenance['content_type'] ?? ''),
                'content_hash' => (string) ($provenance['content_hash'] ?? ''),
                'compressed_bytes' => (int) ($provenance['compressed_bytes'] ?? 0),
                'decompressed_bytes' => (int) ($provenance['decompressed_bytes'] ?? 0),
                'fetched_at' => (string) ($provenance['fetched_at'] ?? now()->toIso8601String()),
            ]);
            $report = AnalysisReport::create([
                'analysis_job_id' => $job->id,
                'public_id' => 'rpt_'.Str::ulid(),
                'schema_version' => (string) config('website_intelligence.reports.schema_version', '1.0'),
                'generated_at' => now(),
                'expires_at' => $job->expires_at,
            ]);

            $candidates = $this->candidates($result['extraction']);
            $max = min(500, max(1, (int) config('website_intelligence.reports.max_observations', 500)));
            foreach (array_slice($candidates, 0, $max) as $candidate) {
                AnalysisObservation::create([
                    'analysis_job_id' => $job->id,
                    'page_fetch_id' => $fetch->id,
                    'public_id' => 'obs_'.Str::ulid(),
                    'evidence_public_id' => 'ev_'.Str::ulid(),
                    'kind' => $candidate['kind'],
                    'source_location' => $candidate['location'],
                    'content_hash' => $fetch->content_hash,
                    'statement' => $this->bound($candidate['statement'], 2000),
                    'excerpt' => $this->bound($candidate['excerpt'], 1000),
                    'observed_at' => $fetch->fetched_at,
                ]);
            }

            return ['report' => $report, 'observation_count' => min(count($candidates), $max)];
        }, 3);
    }

    /** @param array<string, mixed> $extraction @return list<array{kind: string, location: string, statement: string, excerpt: string}> */
    private function candidates(array $extraction): array
    {
        $candidates = [];
        $identity = is_array($extraction['identity'] ?? null) ? $extraction['identity'] : [];
        foreach (['title' => 'Page title', 'description' => 'Meta description', 'canonical_url' => 'Canonical URL'] as $key => $label) {
            if (is_string($identity[$key] ?? null) && $identity[$key] !== '') {
                $candidates[] = $this->candidate('page_identity', 'head.'.$key, $label.': '.$identity[$key], $identity[$key]);
            }
        }
        foreach ((array) ($extraction['heading_details'] ?? []) as $index => $heading) {
            if (is_array($heading) && is_string($heading['text'] ?? null)) {
                $level = is_string($heading['level'] ?? null) ? $heading['level'] : 'heading';
                $candidates[] = $this->candidate('heading', $level.'['.($index + 1).']', strtoupper($level).': '.$heading['text'], $heading['text']);
            }
        }

        $text = is_string($extraction['text'] ?? null) ? $extraction['text'] : '';
        $excerptBytes = max(100, min(1000, (int) config('website_intelligence.reports.text_excerpt_bytes', 1000)));
        for ($offset = 0, $part = 1; $offset < strlen($text) && $part <= 50; $offset += $excerptBytes, $part++) {
            $excerpt = trim(mb_strcut($text, $offset, $excerptBytes, 'UTF-8'));
            if ($excerpt !== '') {
                $candidates[] = $this->candidate('readable_text', 'body.text['.$part.']', 'Readable page text: '.$excerpt, $excerpt);
            }
        }

        $this->appendList($candidates, 'link', 'links', 'Link', (array) ($extraction['links'] ?? []));
        foreach ((array) ($extraction['structured_data'] ?? []) as $index => $item) {
            if (is_array($item)) {
                $summary = implode(', ', array_filter([
                    isset($item['type']) ? 'type '.$item['type'] : null,
                    isset($item['name']) ? 'name '.$item['name'] : null,
                    isset($item['price']) ? 'price '.$item['price'] : null,
                    isset($item['currency']) ? 'currency '.$item['currency'] : null,
                ]));
                if ($summary !== '') {
                    $candidates[] = $this->candidate('structured_data', 'json-ld['.($index + 1).']', 'Structured data: '.$summary, $summary);
                }
            }
        }
        $this->appendList($candidates, 'pricing', 'pricing', 'Pricing signal', (array) ($extraction['pricing_signals'] ?? []));
        $this->appendList($candidates, 'call_to_action', 'cta', 'Call to action', (array) ($extraction['calls_to_action'] ?? []));
        $this->appendList($candidates, 'contact', 'contact', 'Contact method', (array) ($extraction['contact_methods'] ?? []));
        $this->appendList($candidates, 'technology', 'technology', 'Technology indicator', (array) ($extraction['technology_indicators'] ?? []));

        return $candidates;
    }

    /** @param list<array{kind: string, location: string, statement: string, excerpt: string}> $candidates @param array<mixed> $values */
    private function appendList(array &$candidates, string $kind, string $location, string $label, array $values): void
    {
        foreach ($values as $index => $value) {
            if (is_string($value) && $value !== '') {
                $candidates[] = $this->candidate($kind, $location.'['.($index + 1).']', $label.': '.$value, $value);
            }
        }
    }

    /** @return array{kind: string, location: string, statement: string, excerpt: string} */
    private function candidate(string $kind, string $location, string $statement, string $excerpt): array
    {
        return compact('kind', 'location', 'statement', 'excerpt');
    }

    private function bound(string $value, int $max): string
    {
        return mb_strcut(trim($value), 0, $max, 'UTF-8');
    }
}
