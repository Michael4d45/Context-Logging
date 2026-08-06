<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\PHPUnit;

use PHPUnit\Event\Test\Finished as FinishedEvent;
use PHPUnit\Event\Test\FinishedSubscriber;

/**
 * Safety net after tearDown. Usually a no-op because Passed/Failed/Errored
 * already emitted while the Laravel app was still alive.
 *
 * @internal
 */
final class TestFinishedSubscriber implements FinishedSubscriber
{
    public function notify(FinishedEvent $event): void
    {
        PhpunitTestLifecycle::finish(failed: false);
    }
}
