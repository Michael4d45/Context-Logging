<?php

namespace Michael4d45\ContextLogging\Tests;

use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\Tinker\ContextLoggingTinkerShell;
use Michael4d45\ContextLogging\Tinker\TinkerExecutionListener;
use PHPUnit\Framework\Attributes\Test;
use Psy\Configuration;
use Psy\Shell;

class TinkerExecutionListenerTest extends TestCase
{
    protected string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = tempnam(sys_get_temp_dir(), 'context-tinker-log-');

        config()->set('logging.default', 'single');
        config()->set('logging.channels.single', [
            'driver' => 'single',
            'path' => $this->logFile,
            'replace_placeholders' => true,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_emits_after_each_tinker_execution_loop(): void
    {
        if (!class_exists(Shell::class)) {
            $this->markTestSkipped('PsySH is not installed.');
        }

        $config = new Configuration();
        $config->setUsePcntl(false);

        $shell = new Shell($config);
        $contextStore = $this->app->make(ContextStore::class);
        $listener = new TinkerExecutionListener($contextStore);

        $listener->onExecute($shell, 'Log::info("hello");');
        $contextStore->addEvent('info', 'hello from tinker');

        $this->assertSame('tinker', $contextStore->getContext('command'));
        $this->assertSame('tinker', $contextStore->getContext('source'));
        $this->assertSame('Log::info("hello");', $contextStore->getContext('input'));

        $listener->afterLoop($shell);

        $this->assertFalse($contextStore->hasLifecycleStarted());
        $this->assertSame([], $contextStore->getAllContext());
        $this->assertStringContainsString('Tinker execution completed', file_get_contents($this->logFile) ?: '');
    }

    #[Test]
    public function it_emits_failed_when_last_exec_was_unsuccessful(): void
    {
        if (! class_exists(Shell::class)) {
            $this->markTestSkipped('PsySH is not installed.');
        }

        $config = new Configuration();
        $config->setUsePcntl(false);

        $shell = new class($config) extends Shell
        {
            public function getLastExecSuccess(): bool
            {
                return false;
            }
        };

        $contextStore = $this->app->make(ContextStore::class);
        $listener = new TinkerExecutionListener($contextStore);

        $listener->onExecute($shell, 'throw new Exception("boom");');
        $contextStore->addEvent('error', 'tinker', [
            'event' => 'ExecutionFailed',
            'exception' => 'boom',
            'exception_class' => \Exception::class,
        ]);

        $listener->afterLoop($shell);

        $log = file_get_contents($this->logFile) ?: '';
        $this->assertStringContainsString('Tinker execution failed', $log);
        $this->assertStringContainsString('boom', $log);
    }

    #[Test]
    public function it_records_exceptions_on_the_active_tinker_lifecycle(): void
    {
        if (! class_exists(Shell::class)) {
            $this->markTestSkipped('PsySH is not installed.');
        }

        $config = new Configuration();
        $config->setUsePcntl(false);

        $contextStore = $this->app->make(ContextStore::class);
        $shell = new ContextLoggingTinkerShell($contextStore, $config);

        $contextStore->initialize();
        $contextStore->addContexts([
            'command' => 'tinker',
            'source' => 'tinker',
            'mode' => 'interactive',
            'input' => '1/0',
        ]);

        $shell->recordCaughtException(new \RuntimeException('tinker boom'));

        $payload = $contextStore->getPayload();
        $this->assertNotEmpty($payload['events']);
        $this->assertSame('error', $payload['events'][0]['level']);
        $this->assertSame('tinker boom', $payload['events'][0]['context']['exception']);
        $this->assertSame(\RuntimeException::class, $payload['events'][0]['context']['exception_class']);
    }
}