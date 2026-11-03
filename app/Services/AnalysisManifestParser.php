<?php

namespace App\Services;

use JsonException;
use RuntimeException;
use Throwable;

class AnalysisManifestParser
{
    public function __construct(private readonly AcquisitionUrlPolicy $urlPolicy) {}

    /** @return list<array{row_number: int, valid: bool, errors: list<string>, data: array<string, mixed>}> */
    public function parse(string $manifest, string $format): array
    {
        $maxBytes = max(1024, (int) config('website_intelligence.bulk.max_manifest_bytes', 100000));
        if (strlen($manifest) > $maxBytes || str_contains($manifest, "\0") || ! mb_check_encoding($manifest, 'UTF-8')) {
            throw new RuntimeException('manifest_too_large_or_invalid');
        }
        $rawRows = $format === 'json' ? $this->json($manifest) : ($format === 'csv' ? $this->csv($manifest) : throw new RuntimeException('unsupported_format'));
        $maxRows = max(1, (int) config('website_intelligence.bulk.max_rows', 100));
        if (count($rawRows) > $maxRows) {
            throw new RuntimeException('too_many_rows');
        }
        $rows = [];
        $fingerprints = [];
        foreach ($rawRows as $index => $raw) {
            $errors = [];
            if (! is_array($raw)) {
                $raw = [];
                $errors[] = 'row_must_be_an_object';
            }
            $url = is_string($raw['url'] ?? null) ? trim($raw['url']) : '';
            $attestation = is_string($raw['authorization_attestation'] ?? null) ? trim($raw['authorization_attestation']) : '';
            try {
                $url = $this->urlPolicy->parse($url)->url;
            } catch (Throwable) {
                $errors[] = 'invalid_url';
            }
            if (strlen($attestation) < 20 || strlen($attestation) > 500) {
                $errors[] = 'invalid_attestation';
            }
            $context = $raw['user_context'] ?? [];
            if (is_string($context)) {
                $context = $context === '' ? [] : array_map('trim', explode('|', $context));
            }
            if (! is_array($context) || count($context) > 10 || array_filter($context, fn ($item): bool => ! is_string($item) || strlen($item) > 500) !== []) {
                $errors[] = 'invalid_user_context';
            }
            $data = ['url' => $url, 'authorization_attestation' => $attestation, 'user_context' => is_array($context) ? array_values($context) : []];
            $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            if (isset($fingerprints[$fingerprint])) {
                $errors[] = 'duplicate_row';
            }
            $fingerprints[$fingerprint] = true;
            $rows[] = ['row_number' => $index + 1, 'valid' => $errors === [], 'errors' => array_values(array_unique($errors)), 'data' => $data];
        }

        return $rows;
    }

    private function json(string $manifest): array
    {
        try {
            $rows = json_decode($manifest, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('invalid_json');
        }

        return is_array($rows) && array_is_list($rows) ? $rows : throw new RuntimeException('json_must_be_an_array');
    }

    private function csv(string $manifest): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('invalid_csv');
        }
        fwrite($stream, $manifest);
        rewind($stream);
        $headers = fgetcsv($stream, 0, ',', '"', '');
        if (! is_array($headers) || ! in_array('url', $headers, true) || ! in_array('authorization_attestation', $headers, true)) {
            throw new RuntimeException('invalid_csv_headers');
        }
        $rows = [];
        while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if ($values === [null] || $values === []) {
                continue;
            }
            $values = array_pad($values, count($headers), null);
            $rows[] = array_combine($headers, array_slice($values, 0, count($headers))) ?: [];
        }
        fclose($stream);

        return $rows;
    }
}
