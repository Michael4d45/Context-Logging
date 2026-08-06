<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\PHPUnit;

use PHPUnit\Event\Test\Failed as FailedEvent;
use PHPUnit\Event\Test\FailedSubscriber;

/**
 * @internal
 */
final class TestFailedSubscriber implements FailedSubscriber
{
    public function notify(FailedEvent $event): void
    {
        $throwable = $event->throwable();
        PhpunitTestLifecycle::recordFailure(
            $throwable->className(),
            $throwable->message(),
            $throwable->stackTrace()
        );
        PhpunitTestLifecycle::finish(failed: true);
    }
}
