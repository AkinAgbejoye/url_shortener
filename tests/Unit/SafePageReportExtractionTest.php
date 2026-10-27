<?php

namespace Tests\Unit;

use App\Services\SafePageExtractor;
use Tests\TestCase;

class SafePageReportExtractionTest extends TestCase
{
    public function test_offline_fixture_extracts_bounded_report_signals_without_active_content(): void
    {
        $html = file_get_contents(base_path('tests/Fixtures/intelligence/shop-page.html'));
        $this->assertIsString($html);

        $result = (new SafePageExtractor)->extract($html, 'text/html', null, 'https://shop.example/products/widget', 200);
        $extraction = $result['extraction'];

        $this->assertSame('Acme <Store>', $extraction['identity']['title']);
        $this->assertSame('Tools for independent makers', $extraction['identity']['description']);
        $this->assertSame('https://shop.example/products/widget', $extraction['identity']['canonical_url']);
        $this->assertSame(['Build better widgets', 'For small teams'], $extraction['headings']);
        $this->assertSame('Product', $extraction['structured_data'][0]['type']);
        $this->assertSame('29.99', $extraction['structured_data'][0]['price']);
        $this->assertContains('$29.99', $extraction['pricing_signals']);
        $this->assertContains('Learn more', $extraction['calls_to_action']);
        $this->assertContains('Buy now', $extraction['calls_to_action']);
        $this->assertContains('mailto:hello@shop.example', $extraction['contact_methods']);
        $this->assertContains('WordPress', $extraction['technology_indicators']);
        $this->assertContains('Generator: WordPress 7.0', $extraction['technology_indicators']);
        $this->assertNotContains('javascript:alert(1)', $extraction['links']);
        $this->assertStringNotContainsString('window.steal', $extraction['text']);
        $this->assertArrayNotHasKey('html', $result);
    }
}
