<?php

namespace Tests\Unit;

use App\Contracts\DomainNameResolver;
use App\Contracts\RemotePageTransport;
use App\Data\AcquisitionTarget;
use App\Data\RemotePageResponse;
use App\Exceptions\AcquisitionFailure;
use App\Exceptions\AcquisitionPolicyViolation;
use App\Services\AcquisitionUrlPolicy;
use App\Services\SafePageExtractor;
use App\Services\SinglePageAcquirer;
use Tests\TestCase;

class SinglePageAcquirerTest extends TestCase
{
    public function test_redirects_are_re_resolved_and_cannot_reach_private_networks(): void
    {
        $resolver = new class implements DomainNameResolver
        {
            public function resolve(string $host): array
            {
                return $host === 'public.example' ? ['93.184.216.34'] : ['127.0.0.1'];
            }
        };
        $transport = new RecordingTransport([
            new RemotePageResponse(302, ['location' => ['http://internal.example/admin']], '', '93.184.216.34', 1),
        ]);

        $this->expectException(AcquisitionPolicyViolation::class);
        $this->acquirer($resolver, $transport)->acquire('https://public.example/', fn (): bool => false);
        $this->assertCount(1, $transport->targets);
    }

    public function test_dns_rebinding_peer_mismatch_is_rejected(): void
    {
        $transport = new RecordingTransport([
            new RemotePageResponse(200, ['content-type' => ['text/html']], '<p>safe</p>', '142.250.72.14', 1),
        ]);

        $this->expectException(AcquisitionFailure::class);
        $this->acquirer($this->resolver(['93.184.216.34']), $transport)
            ->acquire('https://public.example/', fn (): bool => false);
    }

    public function test_success_is_sanitized_bounded_and_contains_provenance_only(): void
    {
        $html = '<html><head><title> Example </title><script>alert(1)</script></head><body onload="steal()"><h1>Heading</h1><p>Useful text</p><a href="/about#team">About</a><a href="https://user:password@example.com">secret</a></body></html>';
        $compressed = gzencode($html);
        $transport = new RecordingTransport([
            new RemotePageResponse(200, ['content-type' => ['text/html; charset=utf-8'], 'content-encoding' => ['gzip']], $compressed, '93.184.216.34', 1),
        ]);

        $result = $this->acquirer($this->resolver(['93.184.216.34']), $transport)
            ->acquire('https://public.example/', fn (): bool => false);

        $this->assertSame('Example', $result['extraction']['title']);
        $this->assertSame(['Heading'], $result['extraction']['headings']);
        $this->assertStringNotContainsString('alert', $result['extraction']['text']);
        $this->assertSame(['/about'], $result['extraction']['links']);
        $this->assertFalse($result['provenance']['javascript_rendered']);
        $this->assertArrayNotHasKey('body', $result);
    }

    public function test_compressed_bombs_terminate_within_the_decoded_budget(): void
    {
        config(['website_intelligence.budgets.max_decompressed_bytes' => 1024]);
        $bomb = gzencode(str_repeat('A', 4096));
        $transport = new RecordingTransport([
            new RemotePageResponse(200, ['content-type' => ['text/html'], 'content-encoding' => ['gzip']], $bomb, '93.184.216.34', 1),
        ]);

        try {
            $this->acquirer($this->resolver(['93.184.216.34']), $transport)
                ->acquire('https://public.example/', fn (): bool => false);
            $this->fail('Expected the decompression budget to terminate acquisition.');
        } catch (AcquisitionFailure $exception) {
            $this->assertSame('body_too_large', $exception->category);
        }
    }

    public function test_unsupported_content_and_cancellation_terminate(): void
    {
        $unsupported = new RecordingTransport([
            new RemotePageResponse(200, ['content-type' => ['application/pdf']], '%PDF', '93.184.216.34', 1),
        ]);
        try {
            $this->acquirer($this->resolver(['93.184.216.34']), $unsupported)
                ->acquire('https://public.example/', fn (): bool => false);
            $this->fail('Expected unsupported content to terminate acquisition.');
        } catch (AcquisitionFailure $exception) {
            $this->assertSame('unsupported_content', $exception->category);
        }

        $this->expectException(AcquisitionFailure::class);
        $this->acquirer($this->resolver(['93.184.216.34']), new RecordingTransport([]))
            ->acquire('https://public.example/', fn (): bool => true);
    }

    private function acquirer(DomainNameResolver $resolver, RemotePageTransport $transport): SinglePageAcquirer
    {
        return new SinglePageAcquirer(new AcquisitionUrlPolicy($resolver), $transport, new SafePageExtractor);
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

class RecordingTransport implements RemotePageTransport
{
    /** @var list<AcquisitionTarget> */
    public array $targets = [];

    /** @param list<RemotePageResponse> $responses */
    public function __construct(private array $responses) {}

    public function fetch(AcquisitionTarget $target): RemotePageResponse
    {
        $this->targets[] = $target;

        return array_shift($this->responses) ?? throw new AcquisitionFailure('network_failure');
    }
}
