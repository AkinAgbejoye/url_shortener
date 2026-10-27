<?php

namespace Tests\Unit;

use App\Contracts\DomainNameResolver;
use App\Exceptions\AcquisitionPolicyViolation;
use App\Services\AcquisitionUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AcquisitionUrlPolicyTest extends TestCase
{
    #[DataProvider('hostileUrls')]
    public function test_hostile_and_ambiguous_urls_are_blocked(string $url, array $answers): void
    {
        $policy = new AcquisitionUrlPolicy($this->resolver($answers));

        $this->expectException(AcquisitionPolicyViolation::class);
        $policy->approve($url);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function hostileUrls(): iterable
    {
        yield 'loopback v4' => ['http://localhost.example/', ['127.0.0.1']];
        yield 'private v4' => ['http://private.example/', ['10.0.0.1']];
        yield 'metadata' => ['http://metadata.example/', ['169.254.169.254']];
        yield 'loopback v6' => ['http://[::1]/', ['::1']];
        yield 'link local v6' => ['http://[fe80::1]/', ['fe80::1']];
        yield 'mixed answers' => ['https://mixed.example/', ['93.184.216.34', '192.168.1.1']];
        yield 'credentials' => ['https://user:secret@public.example/', ['93.184.216.34']];
        yield 'encoded authority' => ['https://public%2eexample/', ['93.184.216.34']];
        yield 'decimal address' => ['http://2130706433/', ['127.0.0.1']];
        yield 'hex address' => ['http://0x7f000001/', ['127.0.0.1']];
        yield 'blocked port' => ['https://public.example:8443/', ['93.184.216.34']];
        yield 'backslash ambiguity' => ['https://public.example\\@127.0.0.1/', ['93.184.216.34']];
    }

    public function test_public_url_is_normalized_and_all_answers_are_retained(): void
    {
        $policy = new AcquisitionUrlPolicy($this->resolver(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']));

        $target = $policy->approve('HTTPS://Public.Example/path?q=1#fragment');

        $this->assertSame('https://public.example/path?q=1', $target->url);
        $this->assertSame(443, $target->port);
        $this->assertCount(2, $target->approvedIps);
    }

    public function test_dns_answer_count_is_bounded(): void
    {
        config(['website_intelligence.budgets.max_dns_answers' => 2]);
        $policy = new AcquisitionUrlPolicy($this->resolver(['93.184.216.34', '142.250.72.14', '1.1.1.1']));

        $this->expectException(AcquisitionPolicyViolation::class);
        $policy->approve('https://public.example/');
    }

    /** @param list<string> $answers */
    private function resolver(array $answers): DomainNameResolver
    {
        return new class($answers) implements DomainNameResolver
        {
            /** @param list<string> $answers */
            public function __construct(private readonly array $answers) {}

            public function resolve(string $host): array
            {
                return $this->answers;
            }
        };
    }
}
