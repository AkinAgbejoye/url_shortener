<?php

namespace App\Services;

class MonitoringEvidenceCollector
{
    /** @return array<string, array{hash: string, count: int}> */
    public function snapshot(string $body, string $contentType): array
    {
        if (! str_contains(strtolower($contentType), 'html')) {
            return $this->empty();
        }
        $bounded = mb_strcut($body, 0, 1000000, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim(strip_tags($bounded))) ?? '';
        preg_match_all('/(?:[$€£]\s?\d+(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?\s?(?:USD|EUR|GBP))/iu', $text, $prices);
        preg_match_all('/\b(?:buy|subscribe|sign up|get started|book|contact|download|request|shop)\b[^.!?]{0,80}/iu', $text, $calls);
        preg_match_all('~<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $bounded, $structured);

        return [
            'content' => $this->fact([$text]),
            'pricing' => $this->fact($prices[0] ?? []),
            'calls_to_action' => $this->fact($calls[0] ?? []),
            'structured_data' => $this->fact(array_map($this->normalizeJson(...), $structured[1] ?? [])),
        ];
    }

    /** @param list<string> $values @return array{hash: string, count: int} */
    private function fact(array $values): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(fn (string $value): string => mb_strtolower(trim($value)), $values))));
        sort($normalized);

        return ['hash' => hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR)), 'count' => count($normalized)];
    }

    private function normalizeJson(string $value): string
    {
        $decoded = json_decode($value, true, 16);

        return is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
    }

    /** @return array<string, array{hash: string, count: int}> */
    private function empty(): array
    {
        $fact = $this->fact([]);

        return ['content' => $fact, 'pricing' => $fact, 'calls_to_action' => $fact, 'structured_data' => $fact];
    }
}
