<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\PHPUnit;

use PHPUnit\Event\Test\Errored as ErroredEvent;
use PHPUnit\Event\Test\ErroredSubscriber;

/**
 * @internal
 */
final class TestErroredSubscriber implements ErroredSubscriber
{
    public function notify(ErroredEvent $event): void
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
