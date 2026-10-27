<?php

namespace Tests\Contract;

use JsonException;
use Tests\TestCase;

class WebsiteIntelligenceOpenApiTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $document;

    protected function setUp(): void
    {
        parent::setUp();

        $contents = file_get_contents(base_path('docs/openapi/website-intelligence.v1.json'));
        $this->assertIsString($contents);

        try {
            $this->document = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->fail('OpenAPI JSON is invalid: '.$exception->getMessage());
        }
    }

    public function test_document_has_a_bounded_openapi_31_shape_and_resolvable_local_references(): void
    {
        $this->assertSame('3.1.0', $this->document['openapi']);
        $this->assertSame(config('website_intelligence_contract.version'), $this->document['info']['version']);
        $this->assertSame(['/api'], array_column($this->document['servers'], 'url'));
        $this->assertNotEmpty($this->document['paths']);
        $this->assertArrayHasKey('bearerAuth', $this->document['components']['securitySchemes']);

        $this->walk($this->document, function (mixed $value, string $key): void {
            if ($key !== '$ref' || ! is_string($value)) {
                return;
            }

            $this->assertStringStartsWith('#/', $value, 'Only offline local references are allowed.');
            $resolved = $this->document;
            foreach (explode('/', substr($value, 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                $this->assertIsArray($resolved, "Reference {$value} cannot be resolved.");
                $this->assertArrayHasKey($segment, $resolved, "Reference {$value} cannot be resolved.");
                $resolved = $resolved[$segment];
            }
        });
    }

    public function test_route_registry_and_document_operations_match_exactly(): void
    {
        $documentOperations = [];
        foreach ($this->document['paths'] as $path => $pathItem) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (! isset($pathItem[$method])) {
                    continue;
                }

                $operation = $pathItem[$method];
                $documentOperations[$operation['operationId']] = [
                    'method' => $method,
                    'path' => $path,
                    'scope' => $operation['x-required-scope'] ?? null,
                    'statuses' => array_keys($operation['responses']),
                ];
            }
        }

        $this->assertSame(config('website_intelligence_contract.operations'), $documentOperations);
    }

    public function test_every_operation_has_scoped_bearer_security_and_bounded_responses(): void
    {
        foreach (config('website_intelligence_contract.operations') as $operationId => $expected) {
            $operation = $this->document['paths'][$expected['path']][$expected['method']];
            $this->assertSame([['bearerAuth' => []]], $operation['security'], $operationId);
            $this->assertSame($expected['scope'], $operation['x-required-scope'], $operationId);

            foreach ($operation['responses'] as $status => $response) {
                $resolved = $this->resolve($response);
                $headers = $resolved['headers'] ?? [];
                $this->assertArrayHasKey('RateLimit-Limit', $headers, "{$operationId} {$status}");
                $this->assertArrayHasKey('RateLimit-Remaining', $headers, "{$operationId} {$status}");
                $this->assertArrayHasKey('RateLimit-Reset', $headers, "{$operationId} {$status}");
                $this->assertArrayHasKey('application/json', $resolved['content'] ?? [], "{$operationId} {$status}");
            }

            $rateLimited = $this->resolve($operation['responses']['429']);
            $this->assertArrayHasKey('Retry-After', $rateLimited['headers']);
        }
    }

    public function test_errors_and_report_claim_categories_are_explicitly_bounded(): void
    {
        $error = $this->document['components']['schemas']['Error'];
        $this->assertFalse($error['additionalProperties']);
        $this->assertSame(200, $error['properties']['message']['maxLength']);
        $this->assertSame(20, $error['properties']['fields']['maxProperties']);
        $this->assertSame(5, $error['properties']['fields']['additionalProperties']['maxItems']);

        $report = $this->document['components']['schemas']['AnalysisReport'];
        foreach (['observations', 'inferences', 'assumptions', 'user_supplied_context'] as $category) {
            $this->assertContains($category, $report['required']);
            $this->assertArrayHasKey('maxItems', $report['properties'][$category]);
            $this->assertArrayHasKey('$ref', $report['properties'][$category]['items']);
        }

        $states = $this->document['components']['schemas']['AnalysisState']['enum'];
        $this->assertSame(count($states), count(array_unique($states)));
        $this->assertContains('partially_completed', $states);
        $this->assertContains('blocked', $states);
        $this->assertContains('expired', $states);
    }

    public function test_examples_are_deterministic_and_contain_no_sensitive_material(): void
    {
        $encoded = json_encode($this->examples($this->document), JSON_THROW_ON_ERROR);

        foreach (['password', 'secret', 'token=', 'api_key', 'authorization:', '@public.example', '?'] as $sensitive) {
            $this->assertStringNotContainsStringIgnoringCase($sensitive, $encoded);
        }

        preg_match_all('/https?:\\/\\/[^"\\\\]+/', $encoded, $matches);
        foreach ($matches[0] as $url) {
            $host = parse_url(stripslashes($url), PHP_URL_HOST);
            $this->assertSame('public.example', $host);
        }
    }

    public function test_extension_points_are_metadata_not_undocumented_operations(): void
    {
        $extensions = $this->document['components']['x-extension-points'];
        $this->assertSame(
            ['verified_crawls', 'moderation', 'bulk', 'authorized_imports'],
            array_keys($extensions),
        );

        foreach ($extensions as $extension) {
            $this->assertArrayNotHasKey($extension['planned_path'], $this->document['paths']);
            $this->assertMatchesRegularExpression('/^[a-z]+:[a-z]+$/', $extension['required_scope']);
            $this->assertIsInt($extension['issue']);
        }
    }

    /** @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function resolve(array $value): array
    {
        $visited = [];
        while (isset($value['$ref'])) {
            $reference = $value['$ref'];
            $this->assertNotContains($reference, $visited, "Circular reference {$reference}");
            $visited[] = $reference;
            $value = $this->document;
            foreach (explode('/', substr($reference, 2)) as $segment) {
                $value = $value[str_replace(['~1', '~0'], ['/', '~'], $segment)];
            }
        }

        return $value;
    }

    private function walk(mixed $value, callable $callback): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            $callback($item, (string) $key);
            $this->walk($item, $callback);
        }
    }

    /** @return array<string, mixed> */
    private function examples(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $examples = [];
        foreach ($value as $key => $item) {
            if ($key === 'example') {
                $examples[] = $item;
            }
            $examples = [...$examples, ...$this->examples($item)];
        }

        return $examples;
    }
}
