<?php

namespace Michael4d45\ContextLogging\Tests;

use Michael4d45\ContextLogging\ContextLogEmitter;
use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\Profiling\Contracts\ProfilerAdapter;
use Michael4d45\ContextLogging\Profiling\ProfileRef;
use Michael4d45\ContextLogging\Profiling\ProfilerCorrelator;
use PHPUnit\Framework\Attributes\Test;

class ProfilerCorrelatorTest extends TestCase
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
    public function it_returns_empty_when_correlation_disabled(): void
    {
        config()->set('context-logging.profiling.correlate', false);

        $adapter = new class implements ProfilerAdapter {
            public function name(): string
            {
                return 'fake';
            }

            public function detect(): ?ProfileRef
            {
                return new ProfileRef('fake', true, 'id-1');
            }
        };

        $this->assertSame([], (new ProfilerCorrelator([$adapter]))->detect());
    }

    #[Test]
    public function it_collects_enabled_adapter_refs(): void
    {
        config()->set('context-logging.profiling.correlate', true);

        $enabled = new class implements ProfilerAdapter {
            public function name(): string
            {
                return 'spx';
            }

            public function detect(): ?ProfileRef
            {
                return new ProfileRef('spx', true, 'spx-full-1', null, 'http://localhost/?SPX_UI_URI=/');
            }
        };

        $noop = new class implements ProfilerAdapter {
            public function name(): string
            {
                return 'noop';
            }

            public function detect(): ?ProfileRef
            {
                return null;
            }
        };

        $refs = (new ProfilerCorrelator([$enabled, $noop]))->detect();

        $this->assertCount(1, $refs);
        $this->assertSame('spx', $refs[0]->vendor);
        $this->assertSame('spx-full-1', $refs[0]->profileId);
    }

    #[Test]
    public function stub_adapters_return_null(): void
    {
        foreach ([
            new \Michael4d45\ContextLogging\Profiling\Adapters\TidewaysXhprofAdapter,
            new \Michael4d45\ContextLogging\Profiling\Adapters\ExcimerAdapter,
            new \Michael4d45\ContextLogging\Profiling\Adapters\DatadogAdapter,
        ] as $adapter) {
            $this->assertNull($adapter->detect());
        }
    }

    #[Test]
    public function emit_attaches_primary_profile_and_profiles_when_multiple_detected(): void
    {
        config()->set('context-logging.profiling.correlate', true);

        $adapterA = new class implements ProfilerAdapter {
            public function name(): string
            {
                return 'spx';
            }

            public function detect(): ?ProfileRef
            {
                return new ProfileRef('spx', true, 'a');
            }
        };
        $adapterB = new class implements ProfilerAdapter {
            public function name(): string
            {
                return 'xdebug';
            }

            public function detect(): ?ProfileRef
            {
                return new ProfileRef('xdebug', true, 'b', '/tmp/cachegrind.out.1');
            }
        };

        $contextStore = new ContextStore;
        $contextStore->initialize();
        $contextStore->addEvent('info', 'hello', []);

        ContextLogEmitter::emit(
            $contextStore,
            200,
            'Request completed',
            new ProfilerCorrelator([$adapterA, $adapterB]),
        );

        $contents = file_get_contents($this->logFile) ?: '';

        $this->assertStringContainsString('"vendor":"spx"', $contents);
        $this->assertStringContainsString('"profile_id":"a"', $contents);
        $this->assertStringContainsString('"profiles"', $contents);
        $this->assertStringContainsString('"vendor":"xdebug"', $contents);
    }

    #[Test]
    public function emit_skips_profile_when_no_adapters_detect(): void
    {
        config()->set('context-logging.profiling.correlate', true);

        $contextStore = $this->app->make(ContextStore::class);
        $contextStore->initialize();
        $contextStore->addEvent('info', 'Request completed', []);

        ContextLogEmitter::emit(
            $contextStore,
            200,
            'Request completed',
            new ProfilerCorrelator([]),
        );

        $contents = file_get_contents($this->logFile) ?: '';

        $this->assertStringContainsString('Request completed', $contents);
        $this->assertStringNotContainsString('"profile"', $contents);
    }

    #[Test]
    public function profile_ref_serializes_expected_keys(): void
    {
        $ref = new ProfileRef('spx', true, 'key', null, 'http://example.test', ['a' => 1]);

        $this->assertSame([
            'vendor' => 'spx',
            'enabled' => true,
            'profile_id' => 'key',
            'path' => null,
            'url' => 'http://example.test',
            'meta' => ['a' => 1],
        ], $ref->toArray());
    }
}
