<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\PHPUnit;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * PHPUnit extension: one wide event per test when CONTEXT_LOG_PHPUNIT=true.
 *
 * Register in phpunit.xml:
 *
 * <extensions>
 *   <bootstrap class="Michael4d45\ContextLogging\PHPUnit\ContextLoggingExtension"/>
 * </extensions>
 * <php>
 *   <env name="CONTEXT_LOG_PHPUNIT" value="true"/>
 * </php>
 */
final class ContextLoggingExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscribers(
            new TestPreparedSubscriber,
            new TestPassedSubscriber,
            new TestFailedSubscriber,
            new TestErroredSubscriber,
            new TestFinishedSubscriber,
        );
    }
}
