<?php

namespace Tests\Unit;

use App\Exceptions\AcquisitionFailure;
use App\Services\SafePageExtractor;
use Tests\TestCase;

class SafePageExtractorTest extends TestCase
{
    public function test_compressed_payloads_are_rejected_before_decompression(): void
    {
        config(['website_intelligence.budgets.max_compressed_bytes' => 16]);

        $this->expectException(AcquisitionFailure::class);
        (new SafePageExtractor)->extract(str_repeat('x', 17), 'text/html', 'gzip', 'https://example.test', 200);
    }

    public function test_unsupported_content_types_are_rejected(): void
    {
        try {
            (new SafePageExtractor)->extract('%PDF-1.7', 'application/pdf', null, 'https://example.test', 200);
            $this->fail('Expected unsupported content to be rejected.');
        } catch (AcquisitionFailure $exception) {
            $this->assertSame('unsupported_content', $exception->category);
        }
    }

    public function test_empty_html_is_reported_as_malformed_instead_of_leaking_parser_errors(): void
    {
        try {
            (new SafePageExtractor)->extract('', 'text/html', null, 'https://example.test', 200);
            $this->fail('Expected malformed HTML to be rejected.');
        } catch (AcquisitionFailure $exception) {
            $this->assertSame('malformed_document', $exception->category);
        }
    }

    public function test_plain_text_is_normalized_and_bounded(): void
    {
        config(['website_intelligence.budgets.max_text_bytes' => 12]);

        $result = (new SafePageExtractor)->extract("  first\n\nsecond   third  ", 'text/plain', null, 'https://example.test', 200);

        $this->assertSame('first second', $result['extraction']['text']);
        $this->assertSame(12, strlen($result['extraction']['text']));
    }
}
