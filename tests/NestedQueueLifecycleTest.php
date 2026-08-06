<?php

namespace Michael4d45\ContextLogging\Tests;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\PHPUnit\PhpunitTestLifecycle;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

class NestedQueueLifecycleTest extends TestCase
{
    protected string $logFile;

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('context-logging.log.queue', true);
        $app['config']->set('context-logging.log.db', false);
        $app['config']->set('context-logging.phpunit.enabled', true);
        $app['config']->set('context-logging.phpunit.emit_empty', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = tempnam(sys_get_temp_dir(), 'context-queue-nest-');

        config()->set('logging.default', 'single');
        config()->set('logging.channels.single', [
            'driver' => 'single',
            'path' => $this->logFile,
            'replace_placeholders' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function sync_jobs_nest_into_an_active_phpunit_lifecycle(): void
    {
        PhpunitTestLifecycle::start(
            'Tests\\CheckoutTest::test_guard',
            'Tests\\CheckoutTest',
            'test_guard'
        );

        Log::info('before sync job');

        $job = $this->fakeQueueJob('App\\Jobs\\Notify');
        event(new JobProcessing('sync', $job));
        Log::info('inside sync job');
        event(new JobProcessed('sync', $job));

        Log::info('after sync job');

        $store = $this->app->make(ContextStore::class);
        $this->assertTrue($store->hasLifecycleStarted());
        $this->assertFalse($store->hasBeenEmitted());
        $this->assertSame('phpunit', $store->getContext('source'));

        PhpunitTestLifecycle::finish(failed: false);

        $contents = file_get_contents($this->logFile) ?: '';
        $this->assertStringContainsString('Test completed', $contents);
        $this->assertStringContainsString('before sync job', $contents);
        $this->assertStringContainsString('inside sync job', $contents);
        $this->assertStringContainsString('after sync job', $contents);
        $this->assertStringContainsString('JobProcessing', $contents);
        $this->assertStringContainsString('JobProcessed', $contents);
        $this->assertStringNotContainsString('"message":"Job completed"', $contents);
        $this->assertFalse($store->hasLifecycleStarted());
    }

    #[Test]
    public function standalone_jobs_still_emit_their_own_wide_event(): void
    {
        $this->app->make(ContextStore::class)->clear();

        $job = $this->fakeQueueJob('App\\Jobs\\Standalone');
        event(new JobProcessing('sync', $job));
        event(new JobProcessed('sync', $job));

        $contents = file_get_contents($this->logFile) ?: '';
        $this->assertStringContainsString('Job completed', $contents);
        $this->assertStringContainsString('App\\\\Jobs\\\\Standalone', $contents);
        $this->assertFalse($this->app->make(ContextStore::class)->hasLifecycleStarted());
    }

    private function fakeQueueJob(string $name): Job
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('getName')->andReturn($name);
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('getJobId')->andReturn('job-1');
        $job->shouldReceive('payload')->andReturn([
            'displayName' => $name,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['commandName' => $name],
        ]);
        $job->shouldReceive('uuid')->andReturn('uuid-1');
        $job->shouldReceive('resolveName')->andReturn($name);

        return $job;
    }
}
