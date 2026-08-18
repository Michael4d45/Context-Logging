<?php

namespace Michael4d45\ContextLogging\Tests;

use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\Profiling\SpxLifecycle;
use PHPUnit\Framework\Attributes\Test;
use Orchestra\Testbench\TestCase;

class ContextStoreTest extends TestCase
{
    protected ContextStore $contextStore;

    protected string $logFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->contextStore = new ContextStore();
    }

    protected function tearDown(): void
    {
        $this->setSpxStarted(false);

        if ($this->logFile !== '' && is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    protected function setSpxStarted(bool $started): void
    {
        $property = new \ReflectionProperty(SpxLifecycle::class, 'started');
        $property->setValue(null, $started);
    }

    protected function configureSingleLogToTempFile(): void
    {
        $this->logFile = tempnam(sys_get_temp_dir(), 'context-store-test-');
        config()->set('logging.default', 'single');
        config()->set('logging.channels.single', [
            'driver' => 'single',
            'path' => $this->logFile,
            'replace_placeholders' => true,
        ]);
    }

    #[Test]
    public function it_accumulates_context_data()
    {
        $this->contextStore->initialize();
        $this->contextStore->addContext('request_id', '123');
        $this->contextStore->addContext('user_id', 42);

        $this->assertEquals('123', $this->contextStore->getContext('request_id'));
        $this->assertEquals(42, $this->contextStore->getContext('user_id'));
        $this->assertNull($this->contextStore->getContext('nonexistent'));
    }

    #[Test]
    public function it_accumulates_log_events()
    {
        $this->contextStore->initialize();

        $this->contextStore->addEvent('info', 'Order placed', ['order_id' => 123]);
        $this->contextStore->addEvent('error', 'Payment failed', ['error' => 'timeout']);

        $events = $this->contextStore->getEvents();

        $this->assertCount(2, $events);
        $this->assertEquals('info', $events[0]['level']);
        $this->assertEquals('Order placed', $events[0]['message']);
        $this->assertEquals(['order_id' => 123], $events[0]['context']);

        $this->assertEquals('error', $events[1]['level']);
        $this->assertEquals('Payment failed', $events[1]['message']);
        $this->assertEquals(['error' => 'timeout'], $events[1]['context']);
    }

    #[Test]
    public function it_emits_standalone_events_when_no_lifecycle_is_active()
    {
        $this->configureSingleLogToTempFile();

        $this->contextStore->addEvent('info', 'Framework booted');

        $this->assertSame([], $this->contextStore->getEvents());
        $this->assertSame([], $this->contextStore->getBufferedEvents());
        $this->assertFalse($this->contextStore->hasEvents());
        $this->assertStringContainsString('Framework booted', file_get_contents($this->logFile) ?: '');
    }

    #[Test]
    public function it_starts_a_lifecycle_without_carrying_pre_lifecycle_events()
    {
        $this->configureSingleLogToTempFile();

        $this->contextStore->addEvent('info', 'Framework booted');

        $this->contextStore->initialize();

        $this->assertCount(0, $this->contextStore->getEvents());
        $this->assertSame([], $this->contextStore->getBufferedEvents());
        $this->assertStringContainsString('Framework booted', file_get_contents($this->logFile) ?: '');
    }

    #[Test]
    public function it_buffers_pre_lifecycle_events_when_configured_like_http_and_promotes_on_initialize(): void
    {
        $store = new class extends ContextStore {
            protected function shouldBufferPreLifecycleEvents(): bool
            {
                return true;
            }
        };

        $store->addEvent('info', 'channels.php');

        $this->assertCount(1, $store->getBufferedEvents());

        $store->initialize(true);

        $this->assertSame([], $store->getBufferedEvents());
        $this->assertCount(1, $store->getEvents());
        $this->assertSame('channels.php', $store->getEvents()[0]['message']);
    }

    #[Test]
    public function it_generates_structured_payload()
    {
        $this->contextStore->initialize();

        // Add context
        $this->contextStore->addContext('request_id', '123');
        $this->contextStore->addContext('method', 'POST');

        // Add events
        $this->contextStore->addEvent('info', 'Order placed', ['order_id' => 123]);

        // Finalize
        $this->contextStore->finalize(201);

        $payload = $this->contextStore->getPayload();

        $this->assertArrayHasKey('context', $payload);
        $this->assertArrayHasKey('events', $payload);
        $this->assertEquals('123', $payload['context']['request_id']);
        $this->assertEquals('POST', $payload['context']['method']);
        $this->assertEquals(201, $payload['context']['status']);
        $this->assertCount(1, $payload['events']);
    }

    #[Test]
    public function it_tracks_request_duration()
    {
        $this->contextStore->initialize();

        // Simulate some time passing
        usleep(10000); // 10ms

        $this->contextStore->finalize();

        $payload = $this->contextStore->getPayload();

        $this->assertArrayHasKey('duration_ms', $payload['context']);
        $this->assertArrayHasKey('started_at', $payload['context']);
        $this->assertIsFloat($payload['context']['started_at']);
        $this->assertGreaterThan(0, $payload['context']['duration_ms']);
    }

    #[Test]
    public function it_can_be_cleared()
    {
        $this->configureSingleLogToTempFile();

        $this->contextStore->initialize();
        $this->contextStore->addContext('test', 'value');
        $this->contextStore->addEvent('info', 'test message');
        $this->contextStore->clear();
        $this->contextStore->addEvent('info', 'buffered message');

        $this->assertSame([], $this->contextStore->getBufferedEvents());
        $this->assertStringContainsString('buffered message', file_get_contents($this->logFile) ?: '');

        $this->contextStore->clear();

        $this->assertEmpty($this->contextStore->getAllContext());
        $this->assertFalse($this->contextStore->hasEvents());
        $this->assertSame([], $this->contextStore->getBufferedEvents());
    }

    #[Test]
    public function it_tracks_emission_state_per_lifecycle()
    {
        $this->contextStore->initialize();

        $this->assertTrue($this->contextStore->hasLifecycleStarted());
        $this->assertFalse($this->contextStore->hasBeenEmitted());
        $this->assertFalse($this->contextStore->isEmissionSuppressed());

        $this->contextStore->markEmitted();
        $this->contextStore->suppressEmission();

        $this->assertTrue($this->contextStore->hasBeenEmitted());
        $this->assertTrue($this->contextStore->isEmissionSuppressed());

        $this->contextStore->clear();

        $this->assertFalse($this->contextStore->hasLifecycleStarted());
        $this->assertFalse($this->contextStore->hasBeenEmitted());
        $this->assertFalse($this->contextStore->isEmissionSuppressed());
    }

    #[Test]
    public function it_tracks_outbound_http_calls()
    {
        $this->contextStore->initialize();

        $id = $this->contextStore->beginHttpCall([
            'method' => 'GET',
            'url' => 'https://api.example.com/orders',
        ]);

        $this->contextStore->addHttpContext($id, [
            'service' => 'orders-api',
        ]);

        usleep(5000);

        $this->contextStore->completeHttpCall($id, [
            'status' => 200,
        ]);

        $calls = $this->contextStore->getHttpCalls();

        $this->assertCount(1, $calls);
        $this->assertSame('GET', $calls[0]['request']['method']);
        $this->assertSame('https://api.example.com/orders', $calls[0]['request']['url']);
        $this->assertSame('orders-api', $calls[0]['context']['service']);
        $this->assertSame(200, $calls[0]['response']['status']);
        $this->assertArrayHasKey('duration_ms', $calls[0]['response']);
        $this->assertGreaterThan(0, $calls[0]['response']['duration_ms']);
    }

    #[Test]
    public function it_adds_http_calls_to_payload_when_present()
    {
        $this->contextStore->initialize();

        $id = $this->contextStore->beginHttpCall([
            'method' => 'POST',
            'url' => 'https://api.example.com/payments',
        ]);

        $this->contextStore->completeHttpCall($id, [
            'status' => 202,
        ]);

        $payload = $this->contextStore->getPayload();

        $this->assertArrayNotHasKey('http_calls', $payload);
        $this->assertArrayHasKey('events', $payload);
        
        // Find HTTP events in the events array
        $httpEvents = array_filter($payload['events'], function ($event) {
            return $event['message'] === 'HTTP Call';
        });
        
        $this->assertCount(1, $httpEvents);
        
        $httpEvent = reset($httpEvents);
        
        $this->assertArrayHasKey('request', $httpEvent['context']);
        $this->assertArrayHasKey('response', $httpEvent['context']);
        $this->assertSame('POST', $httpEvent['context']['request']['method']);
        $this->assertSame('https://api.example.com/payments', $httpEvent['context']['request']['url']);
        $this->assertSame(202, $httpEvent['context']['response']['status']);
        $this->assertSame($id, $httpEvent['context']['http_call_id']);
        $this->assertArrayNotHasKey('profile_marker_seq', $httpEvent['context']);
        $this->assertIsArray($httpEvent['context']['trace']);
        $this->assertNotEmpty($httpEvent['context']['trace']);
        $this->assertMatchesRegularExpression('/\.php(:\d+)?$/', $httpEvent['context']['trace'][0]);
    }

    #[Test]
    public function it_stamps_profiler_markers_on_outbound_http_calls()
    {
        $this->setSpxStarted(true);
        $this->contextStore->initialize();

        $this->contextStore->addEvent('info', 'Incoming Request');
        $id = $this->contextStore->beginHttpCall([
            'method' => 'GET',
            'url' => 'https://statamic.com/api',
        ]);
        $this->contextStore->completeHttpCall($id, [
            'status' => 200,
        ]);

        $payload = $this->contextStore->getPayload();
        $incoming = $payload['events'][0];
        $http = $payload['events'][1];

        $this->assertSame('Incoming Request', $incoming['message']);
        $this->assertSame(1, $incoming['context']['profile_marker_seq']);
        $this->assertSame('HTTP Call', $http['message']);
        $this->assertSame(2, $http['context']['profile_marker_seq']);
    }

    #[Test]
    public function it_omits_http_calls_from_payload_when_none_exist()
    {
        $this->contextStore->initialize();

        $payload = $this->contextStore->getPayload();

        $this->assertArrayNotHasKey('http_calls', $payload);
        
        // Ensure no HTTP events in the events array
        $httpEvents = array_filter($payload['events'], function ($event) {
            return $event['message'] === 'HTTP Call';
        });
        
        $this->assertEmpty($httpEvents);
    }

    #[Test]
    public function it_ignores_http_tracking_when_disabled()
    {
        $disabledStore = new ContextStore(null, false);
        $disabledStore->initialize();

        $id = $disabledStore->beginHttpCall([
            'method' => 'GET',
            'url' => 'https://api.example.com/users',
        ]);

        $disabledStore->addHttpContext('manual-id', ['foo' => 'bar']);
        $disabledStore->completeHttpCall('manual-id', ['status' => 500]);

        $this->assertSame('', $id);
        $this->assertSame([], $disabledStore->getHttpCalls());
        $this->assertArrayNotHasKey('http_calls', $disabledStore->getPayload());
    }
}
