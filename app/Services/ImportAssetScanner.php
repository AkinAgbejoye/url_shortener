<?php

namespace App\Services;

use RuntimeException;

class ImportAssetScanner
{
    /** @return array{media_type: string, content: string, result: array<string, mixed>} */
    public function scan(string $body, string $contentType, string $path): array
    {
        $max = max(1024, (int) config('website_intelligence.imports.max_asset_bytes', 2000000));
        if ($body === '' || strlen($body) > $max || str_contains($body, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE')) {
            throw new RuntimeException('malware_or_asset_limit');
        }
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (preg_match('/(?:^|\/|\\\\)\.\.(?:\/|\\\\|$)/', rawurldecode($path)) || preg_match('/\.(?:zip|tar|gz|rar|7z|phar|php|exe|js|mjs)$/i', $path)) {
            throw new RuntimeException('unsafe_asset_path_or_archive');
        }
        if ($mediaType === 'text/css' && in_array($extension, ['', 'css'], true)) {
            if (! mb_check_encoding($body, 'UTF-8') || preg_match('/<\?(?:php)?|<script|PK\x03\x04/i', $body)) {
                throw new RuntimeException('content_confusion');
            }
            $content = preg_replace('/@import[^;]*;|url\s*\([^)]*\)|expression\s*\([^)]*\)|behavior\s*:|-moz-binding\s*:/i', '', $body) ?? '';

            return ['media_type' => $mediaType, 'content' => $content, 'result' => ['scanner' => 'bounded-signature-v1', 'outcome' => 'clean_and_sanitized']];
        }

        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        if (! isset($allowed[$mediaType]) || ! in_array($extension, [$allowed[$mediaType], $mediaType === 'image/jpeg' ? 'jpeg' : $allowed[$mediaType]], true)) {
            throw new RuntimeException('unsupported_or_confused_asset');
        }
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
        if ($detected !== $mediaType || preg_match('/<\?(?:php)?|<html|<script|PK\x03\x04/i', substr($body, 0, 512))) {
            throw new RuntimeException('polyglot_or_content_confusion');
        }

        return ['media_type' => $mediaType, 'content' => base64_encode($body), 'result' => ['scanner' => 'bounded-signature-v1', 'outcome' => 'clean']];
    }
}
