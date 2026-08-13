<?php

namespace Michael4d45\ContextLogging\Tests;

use Michael4d45\ContextLogging\ContextLogEmitter;
use Michael4d45\ContextLogging\ContextStore;
use PHPUnit\Framework\Attributes\Test;

class ContextLogEmitterTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_emits_an_interrupted_request_when_shutdown_happens_before_normal_termination(): void
    {
        $contextStore = $this->app->make(ContextStore::class);
        $contextStore->initialize();
        $contextStore->addContexts([
            'method' => 'GET',
            'path' => 'broken-endpoint',
        ]);

        ContextLogEmitter::emitInterruptedLifecycle($contextStore);

        $this->assertTrue($contextStore->hasBeenEmitted());
        $this->assertStringContainsString('Request interrupted', file_get_contents($this->logFile) ?: '');
    }

    #[Test]
    public function it_records_fatal_shutdown_details_when_present(): void
    {
        $contextStore = $this->app->make(ContextStore::class);
        $contextStore->initialize();
        $contextStore->addContexts([
            'method' => 'POST',
            'path' => 'orders',
        ]);

        ContextLogEmitter::emitInterruptedLifecycle($contextStore, [
            'type' => E_ERROR,
            'message' => 'Call to undefined function boom()',
            'file' => '/tmp/test.php',
            'line' => 12,
        ]);

        $contents = file_get_contents($this->logFile) ?: '';

        $this->assertStringContainsString('Request failed', $contents);
        $this->assertStringContainsString('PHP fatal error', $contents);
        $this->assertStringContainsString('undefined function boom', $contents);
    }

    #[Test]
    public function it_does_not_emit_interrupted_after_successful_emit_with_a_completion_event(): void
    {
        config()->set('context-logging.log.request', false);
        config()->set('context-logging.log.response', false);
        config()->set('context-logging.log.user', false);

        $contextStore = $this->app->make(ContextStore::class);
        $contextStore->initialize();
        $contextStore->addContexts([
            'method' => 'POST',
            'path' => 'api/example',
        ]);

        if (!$contextStore->hasEvents()) {
            $contextStore->addEvent('info', 'Request completed', []);
        }

        ContextLogEmitter::emit($contextStore, 200, 'Request completed');

        ContextLogEmitter::emitInterruptedLifecycle($contextStore);

        $contents = file_get_contents($this->logFile) ?: '';

        $this->assertStringContainsString('Request completed', $contents);
        $this->assertStringNotContainsString('Request interrupted', $contents);
    }

    #[Test]
    public function it_registers_a_missing_json_channel_when_the_stack_references_it(): void
    {
        config()->set('logging.default', 'stack');
        config()->set('logging.channels.stack', [
            'driver' => 'stack',
            'channels' => ['json'],
            'ignore_exceptions' => false,
        ]);
        config()->set('logging.channels.json', null);

        $jsonLog = tempnam(sys_get_temp_dir(), 'context-json-');

        $contextStore = $this->app->make(ContextStore::class);
        $contextStore->initialize();
        $contextStore->addEvent('info', 'hello', []);

        // ensureJsonLogChannel runs during emit; point it at our temp file first
        // by letting emit register, then re-point — actually register before emit:
        ContextLogEmitter::ensureJsonLogChannel();
        config()->set('logging.channels.json.with.stream', $jsonLog);

        ContextLogEmitter::emit($contextStore, 200, 'Request completed');

        $this->assertTrue($contextStore->hasBeenEmitted());
        $this->assertNotNull(config('logging.channels.json'));
        $this->assertStringContainsString('Request completed', file_get_contents($jsonLog) ?: '');

        @unlink($jsonLog);
    }

    #[Test]
    public function it_swallows_logging_failures_instead_of_breaking_the_lifecycle(): void
    {
        config()->set('logging.default', 'stack');
        config()->set('logging.channels.stack', [
            'driver' => 'stack',
            'channels' => ['definitely-missing-channel'],
            'ignore_exceptions' => false,
        ]);

        $contextStore = $this->app->make(ContextStore::class);
        $contextStore->initialize();
        $contextStore->addEvent('info', 'hello', []);

        ContextLogEmitter::emit($contextStore, 200, 'Request completed');

        $this->assertTrue($contextStore->hasBeenEmitted());
    }

    #[Test]
    public function it_wraps_standalone_sql_in_idle_parent_context(): void
    {
        $previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'queue:work', '--sleep=3'];

        try {
            ContextLogEmitter::emitStandaloneEvent([
                'level' => 'debug',
                'message' => 'sql',
                'context' => [
                    'SQL' => "select `name`, `payload` from `settings` where `group` = 'backend_features';",
                    'execution_time' => '1.2ms',
                    'trace' => [
                        'app/Providers/AppServiceProvider.php:42',
                        'vendor/spatie/laravel-settings/src/SettingsRepositories/DatabaseSettingsRepository.php:88',
                    ],
                ],
                'timestamp' => microtime(true),
            ]);
        } finally {
            if ($previousArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $previousArgv;
            }
        }

        $payload = $this->lastLogContextPayload();

        $this->assertSame('idle', $payload['context']['source'] ?? null);
        $this->assertSame('queue:work', $payload['context']['command'] ?? null);
        $this->assertSame('app/Providers/AppServiceProvider.php:42', $payload['context']['caller'] ?? null);
        $this->assertCount(1, $payload['events'] ?? []);
        $this->assertSame('sql', $payload['events'][0]['message'] ?? null);
        $this->assertStringContainsString(
            'select `name`, `payload` from `settings`',
            file_get_contents($this->logFile) ?: '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function lastLogContextPayload(): array
    {
        $contents = file_get_contents($this->logFile) ?: '';
        $this->assertNotSame('', $contents);

        $start = strpos($contents, '{');
        $this->assertNotFalse($start, $contents);

        $depth = 0;
        $end = null;
        $length = strlen($contents);
        for ($i = $start; $i < $length; $i++) {
            $char = $contents[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        $this->assertNotNull($end, $contents);
        $decoded = json_decode(substr($contents, $start, $end - $start + 1), true);
        $this->assertIsArray($decoded, $contents);

        if (isset($decoded['context']) && is_array($decoded['context']) && isset($decoded['events'])) {
            return $decoded;
        }

        return $decoded;
    }
}