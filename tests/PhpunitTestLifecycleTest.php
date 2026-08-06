<?php

namespace Michael4d45\ContextLogging\Tests;

use Illuminate\Support\Facades\Log;
use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\PHPUnit\PhpunitTestLifecycle;
use PHPUnit\Framework\Attributes\Test;

class PhpunitTestLifecycleTest extends TestCase
{
    protected string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = tempnam(sys_get_temp_dir(), 'context-phpunit-');

        config()->set('logging.default', 'single');
        config()->set('logging.channels.single', [
            'driver' => 'single',
            'path' => $this->logFile,
            'replace_placeholders' => true,
        ]);
        config()->set('context-logging.phpunit.enabled', true);
        config()->set('context-logging.phpunit.emit_empty', true);
        config()->set('context-logging.log.db', false);
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_emits_a_wide_event_for_a_non_http_test_lifecycle(): void
    {
        PhpunitTestLifecycle::start(
            'Tests\\ExampleTest::test_example',
            'Tests\\ExampleTest',
            'test_example'
        );

        Log::info('checkout guard hit', ['user_id' => 42]);

        PhpunitTestLifecycle::finish(failed: false);

        $contents = file_get_contents($this->logFile) ?: '';
        $this->assertStringContainsString('Test completed', $contents);
        $this->assertStringContainsString('checkout guard hit', $contents);
        $this->assertStringContainsString('phpunit', $contents);
        $this->assertFalse($this->app->make(ContextStore::class)->hasLifecycleStarted());
    }

    #[Test]
    public function it_emits_test_failed_when_marked_failed(): void
    {
        PhpunitTestLifecycle::start('Tests\\ExampleTest::test_fail', 'Tests\\ExampleTest', 'test_fail');
        PhpunitTestLifecycle::recordFailure(\RuntimeException::class, 'expected boom');
        PhpunitTestLifecycle::finish(failed: true);

        $contents = file_get_contents($this->logFile) ?: '';
        $this->assertStringContainsString('Test failed', $contents);
        $this->assertStringContainsString('expected boom', $contents);
    }

    #[Test]
    public function it_does_not_double_emit_when_http_middleware_already_emitted(): void
    {
        PhpunitTestLifecycle::start('Tests\\HttpTest::test_get', 'Tests\\HttpTest', 'test_get');

        $store = $this->app->make(ContextStore::class);
        $store->addEvent('info', 'Incoming Request', []);
        // Simulate EmitContextMiddleware already writing the wide event.
        \Michael4d45\ContextLogging\ContextLogEmitter::emit($store, 200, 'Request completed');

        $before = file_get_contents($this->logFile) ?: '';
        PhpunitTestLifecycle::finish(failed: false);
        $after = file_get_contents($this->logFile) ?: '';

        $this->assertSame($before, $after);
        $this->assertStringContainsString('Request completed', $after);
        $this->assertStringNotContainsString('Test completed', $after);
    }

    #[Test]
    public function it_noops_when_phpunit_mode_is_disabled(): void
    {
        config()->set('context-logging.phpunit.enabled', false);

        PhpunitTestLifecycle::start('Tests\\ExampleTest::test_off', 'Tests\\ExampleTest', 'test_off');
        Log::info('should not be in a test wide event');
        PhpunitTestLifecycle::finish();

        // Without a lifecycle, console mode may standalone-emit; clear file check:
        // start() must not have begun a lifecycle.
        $this->assertFalse($this->app->make(ContextStore::class)->hasLifecycleStarted());
    }
}
