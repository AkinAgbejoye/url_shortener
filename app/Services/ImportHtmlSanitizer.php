<?php

namespace App\Services;

use RuntimeException;

class ImportHtmlSanitizer
{
    /** @return array{html: string, assets: list<array{url: string, token: string}>, summary: array<string, int>, editable: array<string, mixed>} */
    public function sanitize(string $html, string $origin): array
    {
        if (! mb_check_encoding($html, 'UTF-8') || strlen($html) > max(1024, (int) config('website_intelligence.imports.max_page_bytes', 1000000))) {
            throw new RuntimeException('html_limit_or_encoding');
        }
        $dom = new \DOMDocument;
        if (! @$dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)) {
            throw new RuntimeException('invalid_html');
        }
        $removedElements = $removedAttributes = 0;
        $xpath = new \DOMXPath($dom);
        $dangerous = '//script|//form|//input|//button|//textarea|//select|//option|//iframe|//frame|//object|//embed|//applet|//portal|//base|//meta|//audio|//video|//source|//track|//canvas|//svg|//math|//noscript';
        $nodes = [];
        foreach ($xpath->query($dangerous) ?: [] as $node) {
            $nodes[] = $node;
        }
        foreach ($nodes as $node) {
            $node->parentNode?->removeChild($node);
            $removedElements++;
        }

        $assets = [];
        foreach ($xpath->query('//*') ?: [] as $element) {
            if (! $element instanceof \DOMElement) {
                continue;
            }
            $removeNode = $element->hasAttribute('hidden') || preg_match('/(?:display\s*:\s*none|visibility\s*:\s*hidden)/i', $element->getAttribute('style'));
            if ($removeNode) {
                $element->parentNode?->removeChild($element);
                $removedElements++;

                continue;
            }
            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                if (str_starts_with($name, 'on') || in_array($name, ['action', 'formaction', 'srcdoc', 'nonce', 'integrity', 'crossorigin', 'ping', 'background', 'poster', 'data', 'codebase', 'manifest'], true)) {
                    $element->removeAttribute($attribute->name);
                    $removedAttributes++;
                }
            }
            if ($element->hasAttribute('style')) {
                $element->setAttribute('style', $this->css($element->getAttribute('style')));
            }
            if ($element->tagName === 'a') {
                $element->setAttribute('href', '#');
                $element->setAttribute('rel', 'nofollow noopener noreferrer');
                $element->removeAttribute('target');
            }
            if ($element->tagName === 'img') {
                $width = (int) $element->getAttribute('width');
                $height = (int) $element->getAttribute('height');
                if (($width > 0 && $width <= 2) || ($height > 0 && $height <= 2)) {
                    $element->parentNode?->removeChild($element);
                    $removedElements++;

                    continue;
                }
                $url = $this->assetUrl($origin, $element->getAttribute('src'));
                if ($url === null) {
                    $element->removeAttribute('src');
                } else {
                    $token = 'import-asset-'.hash('sha256', $url);
                    $element->setAttribute('src', $token);
                    $assets[] = ['url' => $url, 'token' => $token];
                }
                $element->removeAttribute('srcset');
                $element->setAttribute('loading', 'lazy');
            }
        }
        foreach ($xpath->query('//style') ?: [] as $style) {
            $style->textContent = $this->css($style->textContent);
        }
        foreach ($xpath->query('//link') ?: [] as $link) {
            if (! $link instanceof \DOMElement || strtolower($link->getAttribute('rel')) !== 'stylesheet') {
                $link->parentNode?->removeChild($link);
                $removedElements++;

                continue;
            }
            $url = $this->assetUrl($origin, $link->getAttribute('href'));
            if ($url === null) {
                $link->parentNode?->removeChild($link);
                $removedElements++;
            } else {
                $token = 'import-style-'.hash('sha256', $url);
                $replacement = $dom->createElement('style', $token);
                $link->parentNode?->replaceChild($replacement, $link);
                $assets[] = ['url' => $url, 'token' => $token];
            }
        }

        $output = $dom->saveHTML();
        if (! is_string($output)) {
            throw new RuntimeException('sanitizer_failed');
        }
        $headings = [];
        foreach ($xpath->query('//h1|//h2|//h3') ?: [] as $heading) {
            $text = trim($heading->textContent);
            if ($text !== '') {
                $headings[] = mb_strcut($text, 0, 200, 'UTF-8');
            }
        }

        return [
            'html' => $output,
            'assets' => array_slice(array_values(array_unique($assets, SORT_REGULAR)), 0, max(1, (int) config('website_intelligence.imports.max_assets', 20))),
            'summary' => ['removed_elements' => $removedElements, 'removed_attributes' => $removedAttributes],
            'editable' => ['schema_version' => '1.0', 'title' => mb_strcut((string) ($dom->getElementsByTagName('title')->item(0)?->textContent ?? ''), 0, 200, 'UTF-8'), 'headings' => array_slice($headings, 0, 50)],
        ];
    }

    private function css(string $css): string
    {
        return preg_replace('/@import[^;]*;|url\s*\([^)]*\)|expression\s*\([^)]*\)|behavior\s*:|-moz-binding\s*:|position\s*:\s*fixed/i', '', $css) ?? '';
    }

    private function assetUrl(string $origin, string $reference): ?string
    {
        $reference = trim($reference);
        if ($reference === '' || str_starts_with($reference, 'data:') || str_starts_with($reference, '//')) {
            return null;
        }
        if (str_contains(rawurldecode($reference), '..') || str_contains($reference, "\0")) {
            return null;
        }
        $url = str_starts_with($reference, '/') ? $origin.$reference : (filter_var($reference, FILTER_VALIDATE_URL) ? $reference : $origin.'/'.ltrim($reference, './'));
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME).'://'.(string) parse_url($url, PHP_URL_HOST)) !== strtolower($origin)) {
            return null;
        }
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            return null;
        }

        return strtok($url, '#') ?: null;
    }
}
