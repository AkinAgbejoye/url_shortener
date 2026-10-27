<?php

namespace App\Services;

use App\Contracts\DomainNameResolver;
use App\Data\AcquisitionTarget;
use App\Exceptions\AcquisitionPolicyViolation;

class AcquisitionUrlPolicy
{
    public function __construct(private readonly DomainNameResolver $resolver) {}

    public function approve(string $url): AcquisitionTarget
    {
        $target = $this->parse($url);
        $answers = array_values(array_unique($this->resolver->resolve($target->host)));
        $maxAnswers = max(1, (int) config('website_intelligence.budgets.max_dns_answers', 8));
        if ($answers === [] || count($answers) > $maxAnswers) {
            throw new AcquisitionPolicyViolation('dns_policy');
        }
        foreach ($answers as $answer) {
            if (! $this->publicIp($answer)) {
                throw new AcquisitionPolicyViolation('blocked_network');
            }
        }

        return new AcquisitionTarget($target->url, $target->scheme, $target->host, $target->port, $answers, $answers[0]);
    }

    public function parse(string $url): AcquisitionTarget
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            throw new AcquisitionPolicyViolation('malformed_url');
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new AcquisitionPolicyViolation('malformed_url');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new AcquisitionPolicyViolation('unsupported_scheme');
        }
        if (isset($parts['user']) || isset($parts['pass']) || $this->authorityIsAmbiguous($url)) {
            throw new AcquisitionPolicyViolation('credential_or_ambiguous_target');
        }

        $host = strtolower(rtrim(trim((string) $parts['host'], '[]'), '.'));
        if (! $this->validHost($host)) {
            throw new AcquisitionPolicyViolation('malformed_host');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $allowedPorts = $scheme === 'https' ? [443] : [80];
        if (! in_array($port, $allowedPorts, true)) {
            throw new AcquisitionPolicyViolation('blocked_port');
        }

        $normalizedHost = str_contains($host, ':') ? "[{$host}]" : $host;
        $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $normalized = "{$scheme}://{$normalizedHost}{$path}{$query}";

        return new AcquisitionTarget($normalized, $scheme, $host, $port, [], '');
    }

    public function publicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function authorityIsAmbiguous(string $url): bool
    {
        $remainder = explode('://', $url, 2)[1] ?? '';
        $authority = preg_split('/[\/?#]/', $remainder, 2)[0] ?? '';

        return str_contains($authority, '@') || str_contains($authority, '%');
    }

    private function validHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || preg_match('/[^\x21-\x7e]/', $host)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }
        if (preg_match('/^(?:0x[0-9a-f]+|[0-9.]+)$/i', $host)) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        return str_contains($host, '.');
    }
}
