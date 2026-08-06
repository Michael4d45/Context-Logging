<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\PHPUnit;

/**
 * Emit the per-test wide event before Laravel destroys the application.
 *
 * Use with ContextLoggingExtension (starts the lifecycle on Test\Prepared).
 * Add to your base TestCase:
 *
 *   use Michael4d45\ContextLogging\PHPUnit\LogsTestContext;
 *
 *   abstract class TestCase extends BaseTestCase
 *   {
 *       use LogsTestContext;
 *   }
 *
 * If your TestCase already defines tearDown(), call
 * $this->emitContextLoggingForTest() before parent::tearDown().
 */
trait LogsTestContext
{
    protected function tearDown(): void
    {
        $this->emitContextLoggingForTest();

        parent::tearDown();
    }

    protected function emitContextLoggingForTest(): void
    {
        if (! class_exists(PhpunitTestLifecycle::class) || ! PhpunitTestLifecycle::enabled()) {
            return;
        }

        $failed = false;

        try {
            if (method_exists($this, 'status')) {
                $status = $this->status();
                $failed = $status->isFailure() || $status->isError();
            }
        } catch (\Throwable) {
            $failed = false;
        }

        PhpunitTestLifecycle::finish(failed: $failed);
    }
}
