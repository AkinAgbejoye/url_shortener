<?php

namespace App\Services;

use App\Contracts\CrawlPageFetcher;
use App\Models\ModerationDomainBlock;
use App\Models\SiteImport;
use App\Models\SiteImportAsset;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AuthorizedSiteImporter
{
    public function __construct(
        private readonly CrawlPageFetcher $fetcher,
        private readonly ImportHtmlSanitizer $sanitizer,
        private readonly ImportAssetScanner $scanner,
    ) {}

    /** @return array<string, mixed> */
    public function import(SiteImport $import): array
    {
        $this->authorize($import);
        $started = hrtime(true);
        $origin = 'https://'.$import->verification->domain;
        $page = $this->fetcher->fetch($origin.'/');
        if ($page->status < 200 || $page->status >= 300 || ! str_contains(strtolower($page->contentType), 'text/html')) {
            throw new RuntimeException('unsupported_root_document');
        }
        if (strlen($page->body) > $import->policy['max_total_bytes']) {
            throw new RuntimeException('total_byte_limit');
        }
        if ((hrtime(true) - $started) / 1_000_000_000 > max(1, (int) config('website_intelligence.imports.max_runtime_seconds', 60))) {
            throw new RuntimeException('runtime_limit');
        }
        $sanitized = $this->sanitizer->sanitize($page->body, $origin);
        $html = $sanitized['html'];
        $totalBytes = strlen($page->body);
        $accepted = $rejected = 0;
        $assetHashes = [];
        foreach ($sanitized['assets'] as $assetReference) {
            $import->refresh();
            $this->authorize($import);
            if ($import->cancel_requested_at !== null) {
                throw new RuntimeException('cancelled');
            }
            if ((hrtime(true) - $started) / 1_000_000_000 > max(1, (int) config('website_intelligence.imports.max_runtime_seconds', 60))) {
                throw new RuntimeException('runtime_limit');
            }
            try {
                $asset = $this->fetcher->fetch($assetReference['url']);
                if ($asset->status < 200 || $asset->status >= 300) {
                    throw new RuntimeException('asset_fetch_failed');
                }
                $totalBytes += strlen($asset->body);
                if ($totalBytes > $import->policy['max_total_bytes']) {
                    throw new RuntimeException('total_byte_limit');
                }
                $scanned = $this->scanner->scan($asset->body, $asset->contentType, (string) parse_url($assetReference['url'], PHP_URL_PATH));
                $hash = hash('sha256', $asset->body);
                SiteImportAsset::create([
                    'site_import_id' => $import->id,
                    'public_id' => 'asset_'.Str::ulid(),
                    'source_path_hash' => hash_hmac('sha256', $assetReference['url'], (string) config('app.key')),
                    'media_type' => $scanned['media_type'],
                    'bytes' => strlen($asset->body),
                    'content_hash' => $hash,
                    'content' => $scanned['content'],
                    'scan_result' => $scanned['result'],
                ]);
                $replacement = $scanned['media_type'] === 'text/css' ? $scanned['content'] : 'data:'.$scanned['media_type'].';base64,'.$scanned['content'];
                $html = str_replace($assetReference['token'], htmlspecialchars($replacement, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
                $assetHashes[] = $hash;
                $accepted++;
            } catch (Throwable) {
                if ($totalBytes > $import->policy['max_total_bytes']) {
                    throw new RuntimeException('total_byte_limit');
                }
                $html = str_replace($assetReference['token'], '', $html);
                $rejected++;
            }
        }
        if (preg_match('/<script|<form|\son[a-z]+\s*=|javascript:|(?:src|href)\s*=\s*["\']https?:\/\//i', $html)) {
            throw new RuntimeException('sanitizer_postcondition_failed');
        }

        return [
            'html' => $html,
            'editable' => $sanitized['editable'],
            'summary' => [...$sanitized['summary'], 'accepted_assets' => $accepted, 'rejected_assets' => $rejected],
            'provenance' => [
                'verification_public_id' => $import->verification->public_id,
                'verification_method' => $import->verification->method,
                'verified_at' => $import->verification->verified_at->toISOString(),
                'imported_at' => now()->toISOString(),
                'root_content_hash' => hash('sha256', $page->body),
                'asset_content_hashes' => $assetHashes,
                'declared_license_count' => count($import->declared_licenses),
            ],
        ];
    }

    private function authorize(SiteImport $import): void
    {
        $import->load('verification', 'owner');
        $import->verification->refresh();
        $import->owner->refresh();
        if (! $import->authorized() || ! $import->owner->hasVerifiedEmail() || $import->owner->intelligence_suspended_at !== null
            || ModerationDomainBlock::query()->where('domain_hash', $import->verification->domain_hash)->whereNull('released_at')->exists()) {
            throw new RuntimeException('authorization_expired');
        }
    }
}
