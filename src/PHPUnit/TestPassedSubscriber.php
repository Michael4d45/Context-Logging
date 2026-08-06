<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\PHPUnit;

use PHPUnit\Event\Test\Passed as PassedEvent;
use PHPUnit\Event\Test\PassedSubscriber;

/**
 * Emit before tearDown — Laravel destroys the app container in tearDown, which
 * would clear ContextStore before Test\Finished fires.
 *
 * @internal
 */
final class TestPassedSubscriber implements PassedSubscriber
{
    public function notify(PassedEvent $event): void
    {
        // Success path emits from LogsTestContext::tearDown (before Laravel
        // destroys the app). Passed fires too late. Keep as a no-op safety net.
        PhpunitTestLifecycle::finish(failed: false);
    }
}
