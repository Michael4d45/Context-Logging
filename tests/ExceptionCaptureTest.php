<?php

namespace Michael4d45\ContextLogging\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Michael4d45\ContextLogging\ContextLogEmitter;
use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\Middleware\EmitContextMiddleware;
use Michael4d45\ContextLogging\Middleware\RequestContextMiddleware;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

class ExceptionCaptureTest extends TestCase
{
    protected string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = tempnam(sys_get_temp_dir(), 'context-log-');

        config()->set('logging.default', 'single');
        config()->set('logging.channels.single', [
            'driver' => 'single',
            'path' => $this->logFile,
            'replace_placeholders' => true,
        ]);
        config()->set('context-logging.log.request', false);
        config()->set('context-logging.log.response', false);
        config()->set('context-logging.log.user', false);
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_records_exceptions_thrown_inside_emit_middleware(): void
    {
        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        $middleware = new EmitContextMiddleware($store);

        try {
            $middleware->handle(Request::create('/boom', 'GET'), static function () {
                throw new \RuntimeException('controller blew up');
            });
            $this->fail('Expected exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('controller blew up', $e->getMessage());
        }

        $events = $store->getEvents();
        $this->assertCount(1, $events);
        $this->assertSame('error', $events[0]['level']);
        $this->assertSame('controller blew up', $events[0]['message']);
        $this->assertSame(\RuntimeException::class, $events[0]['context']['exception']);
        $this->assertSame('controller blew up', $events[0]['context']['message']);
        $this->assertArrayHasKey('file', $events[0]['context']);
        $this->assertArrayHasKey('line', $events[0]['context']);
    }

    #[Test]
    public function it_prefers_the_previous_exception_message_for_display(): void
    {
        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        $root = new RouteNotFoundException('Route [two-factor.enable] not defined.');
        $wrapper = new \RuntimeException('View: two-factor.blade.php', 0, $root);

        $store->addException($wrapper);

        $event = $store->getEvents()[0];
        $this->assertSame('Route [two-factor.enable] not defined.', $event['message']);
        $this->assertSame(\RuntimeException::class, $event['context']['exception']);
        $this->assertSame(RouteNotFoundException::class, $event['context']['previous']['exception']);
        $this->assertSame('Route [two-factor.enable] not defined.', $event['context']['previous']['message']);
    }

    #[Test]
    public function it_dedupes_the_same_exception_across_report_paths(): void
    {
        $store = $this->app->make(ContextStore::class);
        $store->initialize();

        $e = new \InvalidArgumentException('dup');

        $this->assertTrue($store->addException($e));
        $this->assertFalse($store->addException($e));
        $this->assertCount(1, $store->getEvents());
    }

    #[Test]
    public function it_captures_exceptions_reported_through_the_exception_handler(): void
    {
        $store = $this->app->make(ContextStore::class);
        $store->initialize();
        $store->addContexts([
            'method' => 'GET',
            'path' => 'two-factor',
        ]);

        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            ->report(new \RuntimeException('reported boom'));

        $this->assertTrue($store->hasErrorEvents());
        $this->assertSame('reported boom', $store->getEvents()[0]['message']);
    }

    #[Test]
    public function it_emits_request_failed_with_exception_details_on_5xx(): void
    {
        $store = $this->app->make(ContextStore::class);
        $store->initialize();
        $store->addContexts([
            'method' => 'GET',
            'path' => 'two-factor',
        ]);
        $store->addException(new RouteNotFoundException('Route [two-factor.enable] not defined.'));

        $middleware = new EmitContextMiddleware($store);
        $response = new Response('Server Error', 500);

        $middleware->terminate(Request::create('/two-factor', 'GET'), $response);

        $contents = file_get_contents($this->logFile) ?: '';
        $this->assertStringContainsString('Request failed', $contents);
        $this->assertStringContainsString('Route [two-factor.enable] not defined.', $contents);
        $this->assertTrue($store->hasBeenEmitted());
    }

    #[Test]
    public function it_captures_exceptions_during_a_full_http_request(): void
    {
        Route::middleware([
            RequestContextMiddleware::class,
            EmitContextMiddleware::class,
        ])->get('/explode', function () {
            throw new \RuntimeException('full stack boom');
        });

        $response = $this->get('/explode');

        $response->assertStatus(500);

        $contents = file_get_contents($this->logFile) ?: '';
        $this->assertStringContainsString('Request failed', $contents);
        $this->assertStringContainsString('full stack boom', $contents);
        $this->assertStringContainsString(\RuntimeException::class, $contents);
    }
}
