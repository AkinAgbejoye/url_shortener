<?php

namespace App\Services;

use App\Exceptions\AcquisitionFailure;
use DOMDocument;
use DOMElement;
use DOMXPath;

class SafePageExtractor
{
    /** @return array{extraction: array<string, mixed>, provenance: array<string, mixed>} */
    public function extract(string $body, string $contentType, ?string $contentEncoding, string $sourceUrl, int $status): array
    {
        $compressedBytes = strlen($body);
        $decoded = $this->decode($body, $contentEncoding);
        $mime = strtolower(trim(explode(';', $contentType, 2)[0]));
        if (! in_array($mime, ['text/html', 'application/xhtml+xml', 'text/plain'], true)) {
            throw new AcquisitionFailure('unsupported_content');
        }

        if ($mime === 'text/plain') {
            $text = $this->boundedText($decoded);
            $extraction = ['title' => null, 'headings' => [], 'text' => $text, 'links' => []];
        } else {
            $extraction = $this->html($decoded);
        }

        $parts = parse_url($sourceUrl);
        $origin = strtolower((string) ($parts['scheme'] ?? '')).'://'.strtolower((string) ($parts['host'] ?? ''));

        return [
            'extraction' => $extraction,
            'provenance' => [
                'source_origin' => $origin,
                'source_hash' => hash('sha256', $sourceUrl),
                'fetched_at' => now()->utc()->toIso8601String(),
                'status' => $status,
                'content_type' => $mime,
                'compressed_bytes' => $compressedBytes,
                'decompressed_bytes' => strlen($decoded),
                'content_hash' => hash('sha256', $decoded),
                'javascript_rendered' => false,
            ],
        ];
    }

    private function decode(string $body, ?string $contentEncoding): string
    {
        $maxCompressed = max(1024, (int) config('website_intelligence.budgets.max_compressed_bytes', 1000000));
        $maxDecoded = max(1024, (int) config('website_intelligence.budgets.max_decompressed_bytes', 5000000));
        if (strlen($body) > $maxCompressed) {
            throw new AcquisitionFailure('compressed_body_too_large');
        }

        $encoding = strtolower(trim((string) $contentEncoding));
        if ($encoding === '' || $encoding === 'identity') {
            return strlen($body) > $maxDecoded ? throw new AcquisitionFailure('body_too_large') : $body;
        }
        if (! in_array($encoding, ['gzip', 'x-gzip'], true)) {
            throw new AcquisitionFailure('unsupported_content_encoding');
        }

        $inflater = @inflate_init(ZLIB_ENCODING_GZIP);
        if ($inflater === false) {
            throw new AcquisitionFailure('malformed_compressed_body');
        }

        $decoded = '';
        $length = strlen($body);
        for ($offset = 0; $offset < $length; $offset += 1024) {
            $flush = $offset + 1024 >= $length ? ZLIB_FINISH : ZLIB_SYNC_FLUSH;
            $chunk = @inflate_add($inflater, substr($body, $offset, 1024), $flush);
            if ($chunk === false) {
                throw new AcquisitionFailure('malformed_compressed_body');
            }
            $decoded .= $chunk;
            if (strlen($decoded) > $maxDecoded) {
                throw new AcquisitionFailure('body_too_large');
            }
        }

        return $decoded;
    }

    /** @return array{title: ?string, headings: list<string>, text: string, links: list<string>} */
    private function html(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)) {
                throw new AcquisitionFailure('malformed_document');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//*');
        if ($nodes === false || $nodes->length > max(1, (int) config('website_intelligence.budgets.max_dom_nodes', 20000))) {
            throw new AcquisitionFailure('document_too_complex');
        }

        foreach (['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'object', 'embed', 'form', 'input', 'button'] as $tag) {
            $matches = $document->getElementsByTagName($tag);
            while ($matches->length > 0) {
                $node = $matches->item(0);
                $node?->parentNode?->removeChild($node);
            }
        }
        foreach ($xpath->query('//@*') ?: [] as $attribute) {
            if (str_starts_with(strtolower($attribute->nodeName), 'on') || strtolower($attribute->nodeName) === 'style') {
                $attribute->ownerElement?->removeAttribute($attribute->nodeName);
            }
        }

        $titleNode = $document->getElementsByTagName('title')->item(0);
        $title = $titleNode ? $this->boundedText($titleNode->textContent, 300) : null;
        $headings = [];
        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') ?: [] as $heading) {
            $value = $this->boundedText($heading->textContent, 500);
            if ($value !== '' && count($headings) < 100) {
                $headings[] = $value;
            }
        }

        $links = [];
        $maxLinks = max(0, (int) config('website_intelligence.budgets.max_links', 500));
        foreach ($document->getElementsByTagName('a') as $anchor) {
            if (! $anchor instanceof DOMElement || count($links) >= $maxLinks) {
                break;
            }
            $href = trim($anchor->getAttribute('href'));
            $safeHref = $this->safeLink($href);
            if ($safeHref !== null) {
                $links[] = $safeHref;
            }
        }

        return [
            'title' => $title === '' ? null : $title,
            'headings' => array_values(array_unique($headings)),
            'text' => $this->boundedText($document->textContent),
            'links' => array_values(array_unique($links)),
        ];
    }

    private function boundedText(string $text, ?int $max = null): string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $text));
        $limit = $max ?? max(1, (int) config('website_intelligence.budgets.max_text_bytes', 500000));

        return substr($normalized, 0, $limit);
    }

    private function safeLink(string $href): ?string
    {
        if ($href === '' || strlen($href) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $href)) {
            return null;
        }
        if (str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
            return explode('#', $href, 2)[0];
        }
        $parts = parse_url($href);
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return explode('#', $href, 2)[0];
    }
}
