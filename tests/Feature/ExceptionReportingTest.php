<?php

namespace Tests\Feature;

use App\Contracts\ExternalExceptionReporter;
use App\Reporting\NullExternalExceptionReporter;
use App\Reporting\SentryExceptionReporter;
use App\Services\UrlShortenerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Sentry\State\HubInterface;
use Tests\TestCase;

class ExceptionReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unhandled_exception_is_reported_once_with_safe_request_context(): void
    {
        config([
            'app.debug' => false,
            'logging.exception_channel' => 'exceptions',
        ]);

        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with('unhandled_exception', \Mockery::on(
                fn (array $context): bool => $context === [
                    'exception_class' => RuntimeException::class,
                    'request_id' => 'exception-request-id',
                    'url_path' => 'api/v1/urls',
                    'http_method' => 'POST',
                    'user_agent' => 'ObservabilityTest/1.0',
                ]
            ));
        Log::partialMock()
            ->shouldReceive('channel')
            ->once()
            ->with('exceptions')
            ->andReturn($logger);

        $this->failingShortener('Controlled application failure.');

        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer top-secret-token',
            'Idempotency-Key' => 'top-secret-idempotency-key',
            'User-Agent' => 'ObservabilityTest/1.0',
            'X-Request-ID' => 'exception-request-id',
        ])->postJson('/api/v1/urls', [
            'long_url' => 'https://example.com/sensitive-payload',
        ]);

        $response->assertInternalServerError();
        $this->assertStringNotContainsString('Controlled application failure.', $response->getContent());
        $this->assertStringNotContainsString('top-secret', $response->getContent());
    }

    public function test_a_reporting_failure_does_not_replace_the_original_response(): void
    {
        config([
            'app.debug' => false,
            'logging.exception_channel' => 'exceptions',
        ]);

        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->andThrow(new RuntimeException('Reporter unavailable.'));
        Log::partialMock()
            ->shouldReceive('channel')
            ->once()
            ->with('exceptions')
            ->andReturn($logger);

        $this->failingShortener('Original application failure.');

        $response = $this->postJson('/api/v1/urls', [
            'long_url' => 'https://example.com',
        ]);

        $response->assertInternalServerError();
        $this->assertStringNotContainsString('Reporter unavailable.', $response->getContent());
        $this->assertStringNotContainsString('Original application failure.', $response->getContent());
    }

    public function test_an_external_report_receives_only_the_safe_context_allowlist(): void
    {
        config(['app.debug' => false]);
        Log::spy();
        $externalReporter = \Mockery::mock(ExternalExceptionReporter::class);
        $externalReporter->shouldReceive('report')
            ->once()
            ->with(
                \Mockery::on(fn (RuntimeException $exception): bool => $exception->getMessage() === 'External failure.'),
                [
                    'exception_class' => RuntimeException::class,
                    'request_id' => 'external-request-id',
                    'url_path' => 'api/v1/urls',
                    'http_method' => 'POST',
                    'user_agent' => 'ExternalTest/1.0',
                ],
            );
        $this->app->instance(ExternalExceptionReporter::class, $externalReporter);
        $this->failingShortener('External failure.');

        $this->withHeaders([
            'Authorization' => 'Bearer top-secret-token',
            'Idempotency-Key' => 'top-secret-idempotency-key',
            'User-Agent' => 'ExternalTest/1.0',
            'X-Request-ID' => 'external-request-id',
        ])->postJson('/api/v1/urls', [
            'long_url' => 'https://example.com/sensitive-payload',
        ])->assertInternalServerError();
    }

    public function test_an_external_transport_failure_does_not_replace_the_original_response(): void
    {
        config(['app.debug' => false]);
        Log::spy();
        $externalReporter = \Mockery::mock(ExternalExceptionReporter::class);
        $externalReporter->shouldReceive('report')
            ->once()
            ->andThrow(new RuntimeException('External transport unavailable.'));
        $this->app->instance(ExternalExceptionReporter::class, $externalReporter);
        $this->failingShortener('Original application failure.');

        $response = $this->postJson('/api/v1/urls', [
            'long_url' => 'https://example.com',
        ]);

        $response->assertInternalServerError();
        $this->assertStringNotContainsString('External transport unavailable.', $response->getContent());
        $this->assertStringNotContainsString('Original application failure.', $response->getContent());
    }

    public function test_external_reporting_is_disabled_without_a_dsn(): void
    {
        config(['sentry.dsn' => null]);
        $this->app->forgetInstance(ExternalExceptionReporter::class);

        $this->assertInstanceOf(
            NullExternalExceptionReporter::class,
            $this->app->make(ExternalExceptionReporter::class),
        );
    }

    public function test_a_configured_dsn_enables_the_sentry_transport(): void
    {
        config(['sentry.dsn' => 'https://public@example.com/1']);
        $this->app->instance(HubInterface::class, \Mockery::mock(HubInterface::class));
        $this->app->forgetInstance(ExternalExceptionReporter::class);

        $this->assertInstanceOf(
            SentryExceptionReporter::class,
            $this->app->make(ExternalExceptionReporter::class),
        );
    }

    private function failingShortener(string $message): void
    {
        $shortener = \Mockery::mock(UrlShortenerService::class);
        $shortener->shouldReceive('shorten')
            ->once()
            ->andThrow(new RuntimeException($message));

        $this->app->instance(UrlShortenerService::class, $shortener);
    }
}
