<?php

namespace App\Services;

use App\Contracts\DomainNameResolver;

class NativeDomainNameResolver implements DomainNameResolver
{
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP)) {
                $addresses[] = strtolower($address);
            }
        }

        return array_values(array_unique($addresses));
    }
}
