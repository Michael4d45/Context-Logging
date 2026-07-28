<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Tests;

use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\Sentry\SentryBridge;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Severity;
use Sentry\Stacktrace;

class SentryBridgeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('context-logging.sentry.enabled', true);
        $app['config']->set('context-logging.sentry.drop', true);
        $app['config']->set('context-logging.sentry.capture_transactions', false);
        $app['config']->set('context-logging.sentry.max_breadcrumbs', 20);
        $app['config']->set('context-logging.sentry.max_frames', 40);
    }

    public function test_normalize_captures_exception_event_with_collapsed_trace(): void
    {
        $store = $this->app->make(ContextStore::class);
        $store->initialize();
        $bridge = new SentryBridge($store);

        $exception = new \RuntimeException('boom from billing', 0);
        $event = Event::createEvent(EventId::generate());
        $event->setLevel(Severity::error());

        $absolute = base_path('app/Billing/Actions/Fail.php');
        $stacktrace = new Stacktrace([
            new Frame('vendorCall', base_path('vendor/foo/bar.php'), 10, null, base_path('vendor/foo/bar.php'), [], false),
            new Frame('fail', $absolute, 42, null, $absolute, [], true),
        ]);
        $event->setExceptions([new ExceptionDataBag($exception, $stacktrace)]);

        $payload = $bridge->normalize($event, EventHint::fromArray(['exception' => $exception]));

        $this->assertNotNull($payload);
        $this->assertSame('sentry', $payload['source']);
        $this->assertSame(\RuntimeException::class, $payload['exception']);
        $this->assertSame('boom from billing', $payload['message']);
        $this->assertSame('error', $payload['level']);
        $this->assertContains('app/Billing/Actions/Fail.php:42', $payload['trace']);
        $this->assertStringNotContainsString('vendor/', implode("\n", $payload['trace']));
    }

    public function test_capture_adds_event_to_context_store_and_dedupes_by_event_id(): void
    {
        $store = $this->app->make(ContextStore::class);
        $store->initialize();
        $bridge = new SentryBridge($store);

        $exception = new \InvalidArgumentException('bad arg');
        $eventId = EventId::generate();
        $event = Event::createEvent($eventId);
        $event->setLevel(Severity::error());
        $event->setExceptions([new ExceptionDataBag($exception)]);
        $hint = EventHint::fromArray(['exception' => $exception]);

        $bridge->capture($event, $hint);
        $bridge->capture($event, $hint);

        $events = $store->getEvents();
        $this->assertCount(1, $events);
        $this->assertSame(\InvalidArgumentException::class, $events[0]['message']);
        $this->assertSame('sentry', $events[0]['context']['source']);
        $this->assertSame((string) $eventId, $events[0]['context']['sentry_event_id']);
    }

    public function test_normalize_skips_transactions_unless_enabled(): void
    {
        $store = $this->app->make(ContextStore::class);
        $bridge = new SentryBridge($store);

        $event = Event::createTransaction(EventId::generate());
        $this->assertNull($bridge->normalize($event));

        config(['context-logging.sentry.capture_transactions' => true]);
        $payload = $bridge->normalize($event);
        // Transactions still need a message or exception to be kept.
        $this->assertNull($payload);

        $event->setMessage('checkout.pay');
        $this->assertNotNull($bridge->normalize($event));
    }

    public function test_normalize_skips_configured_absolute_ignore_paths(): void
    {
        config([
            'context-logging.trace.ignore_paths' => [
                'vendor',
                '/opt/extra-packages/vendor',
            ],
        ]);

        $store = $this->app->make(ContextStore::class);
        $bridge = new SentryBridge($store);

        $exception = new \RuntimeException('sidecar frame');
        $event = Event::createEvent(EventId::generate());
        $event->setLevel(Severity::error());

        $appFile = base_path('app/Billing/Actions/Fail.php');
        $sidecar = '/opt/extra-packages/vendor/acme/pkg/src/Middleware/EmitContextMiddleware.php';
        $stacktrace = new Stacktrace([
            new Frame('emit', $sidecar, 41, null, $sidecar, [], false),
            new Frame('fail', $appFile, 42, null, $appFile, [], true),
        ]);
        $event->setExceptions([new ExceptionDataBag($exception, $stacktrace)]);

        $payload = $bridge->normalize($event, EventHint::fromArray(['exception' => $exception]));

        $this->assertNotNull($payload);
        $this->assertContains('app/Billing/Actions/Fail.php:42', $payload['trace']);
        $this->assertStringNotContainsString('extra-packages/vendor', implode("\n", $payload['trace']));
    }
}
