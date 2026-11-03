<?php

namespace App\Services;

class RobotsPolicy
{
    /** @param list<array{allow: bool, path: string}> $rules @param list<string> $sitemaps */
    public function __construct(public readonly array $rules, public readonly array $sitemaps, public readonly string $contentHash) {}

    public static function parse(string $body): self
    {
        $rules = [];
        $sitemaps = [];
        $applies = false;
        foreach (preg_split('/\R/', mb_strcut($body, 0, 500000, 'UTF-8')) ?: [] as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                $applies = $value === '*';

                continue;
            }
            if ($field === 'sitemap' && filter_var($value, FILTER_VALIDATE_URL)) {
                $sitemaps[] = $value;

                continue;
            }
            if ($applies && in_array($field, ['allow', 'disallow'], true) && $value !== '') {
                $rules[] = ['allow' => $field === 'allow', 'path' => $value];
            }
        }

        return new self(array_slice($rules, 0, 500), array_slice(array_values(array_unique($sitemaps)), 0, 10), hash('sha256', $body));
    }

    public function allows(string $path): bool
    {
        $winner = null;
        foreach ($this->rules as $rule) {
            $pattern = preg_quote($rule['path'], '/');
            $pattern = str_replace(['\*', '\$'], ['.*', '$'], $pattern);
            if (preg_match('/^'.$pattern.'/', $path) && ($winner === null || strlen($rule['path']) >= strlen($winner['path']))) {
                $winner = $rule;
            }
        }

        return $winner === null || $winner['allow'];
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return ['standard' => 'RFC 9309', 'content_hash' => $this->contentHash, 'rules' => $this->rules, 'sitemap_count' => count($this->sitemaps)];
    }
}
