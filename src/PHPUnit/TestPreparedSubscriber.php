<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\PHPUnit;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Prepared as PreparedEvent;
use PHPUnit\Event\Test\PreparedSubscriber;

/**
 * Starts a test lifecycle after setUp so the Laravel app (and ContextStore) exist.
 *
 * @internal
 */
final class TestPreparedSubscriber implements PreparedSubscriber
{
    public function notify(PreparedEvent $event): void
    {
        $test = $event->test();
        $class = null;
        $method = null;

        if ($test instanceof TestMethod) {
            $class = $test->className();
            $method = $test->methodName();
        }

        PhpunitTestLifecycle::start($test->id(), $class, $method);
    }
}
