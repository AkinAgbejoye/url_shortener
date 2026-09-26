<?php

namespace Tests\Unit;

use App\Reporting\SentryExceptionReporter;
use App\Support\SentryEventSanitizer;
use App\Support\SentryIntegrationFilter;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Tests\TestCase;

class SentryExceptionReporterTest extends TestCase
{
    public function test_it_delivers_an_exception_with_only_bounded_request_context(): void
    {
        $exception = new RuntimeException('Original failure.');
        $capturedScope = null;
        $hub = \Mockery::mock(HubInterface::class);
        $hub->shouldReceive('withScope')
            ->once()
            ->andReturnUsing(function (callable $callback) use (&$capturedScope): void {
                $capturedScope = new Scope;
                $callback($capturedScope);
            });
        $hub->shouldReceive('captureException')
            ->once()
            ->with($exception);

        (new SentryExceptionReporter($hub))->report($exception, [
            'exception_class' => RuntimeException::class,
            'request_id' => 'request-123',
            'url_path' => 'api/v1/urls',
            'http_method' => 'POST',
            'user_agent' => 'TestAgent/1.0',
        ]);

        $event = Event::createEvent();
        $capturedScope->applyToEvent($event);

        $this->assertSame('request-123', $event->getTags()['request_id']);
        $this->assertSame([
            'request_id' => 'request-123',
            'url_path' => 'api/v1/urls',
            'http_method' => 'POST',
            'user_agent' => 'TestAgent/1.0',
        ], $event->getContexts()['request_summary']);
    }

    public function test_the_final_event_sanitizer_removes_sensitive_payloads(): void
    {
        $frame = new Frame('shorten', '/app/Service.php', 10, vars: [
            'long_url' => 'https://secret.example/private',
            'idempotency_key' => 'top-secret-key',
        ]);
        $exception = new ExceptionDataBag(
            new RuntimeException('Failed for https://secret.example/private'),
            new Stacktrace([$frame]),
        );
        $event = Event::createEvent()
            ->setMessage('Sensitive message with top-secret-key')
            ->setTransaction('https://secret.example/private')
            ->setRequest([
                'url' => 'https://short.example/api?token=secret',
                'headers' => ['Authorization' => 'Bearer secret'],
                'data' => ['long_url' => 'https://secret.example/private'],
            ])
            ->setUser(UserDataBag::createFromUserIpAddress('192.0.2.1'))
            ->setExtra(['idempotency_key' => 'top-secret-key'])
            ->setTags(['request_id' => 'request-123', 'unsafe' => 'secret'])
            ->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'request', 'secret')])
            ->setExceptions([$exception]);

        SentryEventSanitizer::sanitize($event);

        $this->assertSame([], $event->getRequest());
        $this->assertNull($event->getUser());
        $this->assertSame([], $event->getExtra());
        $this->assertSame([], $event->getBreadcrumbs());
        $this->assertSame(['request_id' => 'request-123'], $event->getTags());
        $this->assertSame('Unhandled application exception.', $event->getMessage());
        $this->assertNull($event->getTransaction());
        $this->assertSame('Unhandled application exception.', $exception->getValue());
        $this->assertSame([], $frame->getVars());
    }

    public function test_all_automatic_sentry_integrations_are_disabled(): void
    {
        $this->assertSame([], SentryIntegrationFilter::filter([new \stdClass]));
    }
}
