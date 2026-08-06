<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\PHPUnit;

use Illuminate\Support\Str;
use Michael4d45\ContextLogging\ContextLogEmitter;
use Michael4d45\ContextLogging\ContextStore;

/**
 * Per-test context lifecycle for PHPUnit (similar to Tinker's per-eval emit).
 */
final class PhpunitTestLifecycle
{
    public static function enabled(): bool
    {
        if (function_exists('app')) {
            try {
                if (app()->bound('config')) {
                    return (bool) config('context-logging.phpunit.enabled', false);
                }
            } catch (\Throwable) {
                // Fall through to env.
            }
        }

        return filter_var(
            getenv('CONTEXT_LOG_PHPUNIT') ?: ($_ENV['CONTEXT_LOG_PHPUNIT'] ?? false),
            FILTER_VALIDATE_BOOL
        );
    }

    public static function start(string $testId, ?string $className = null, ?string $methodName = null): void
    {
        if (! self::enabled()) {
            return;
        }

        $store = self::store();
        if ($store === null) {
            return;
        }

        $store->initialize();
        $store->addContexts(array_filter([
            'source' => 'phpunit',
            'run_id' => (string) Str::uuid(),
            'timestamp' => function_exists('now') ? now()->toISOString() : gmdate('c'),
            'test' => $testId,
            'test_class' => $className,
            'test_method' => $methodName,
        ], static fn ($v) => $v !== null && $v !== ''));
    }

    public static function recordFailure(string $className, string $message, ?string $stackTrace = null): void
    {
        if (! self::enabled()) {
            return;
        }

        $store = self::store();
        if ($store === null || ! $store->hasLifecycleStarted() || $store->hasBeenEmitted()) {
            return;
        }

        $context = [
            'exception' => $className,
            'message' => $message,
        ];

        if (is_string($stackTrace) && $stackTrace !== '') {
            $context['stack_trace'] = $stackTrace;
        }

        $store->addEvent('error', $message !== '' ? $message : $className, $context);
    }

    public static function finish(bool $failed = false): void
    {
        if (! self::enabled()) {
            return;
        }

        $store = self::store();
        if ($store === null) {
            return;
        }

        // HTTP feature tests already emitted via EmitContextMiddleware.
        if ($store->hasBeenEmitted()) {
            $store->clear();

            return;
        }

        if (! $store->hasLifecycleStarted()) {
            return;
        }

        $emitEmpty = (bool) (function_exists('config')
            ? config('context-logging.phpunit.emit_empty', true)
            : true);

        if (! $store->hasEvents() && ! $emitEmpty) {
            $store->clear();

            return;
        }

        if (! $store->hasEvents()) {
            $store->addEvent(
                $failed ? 'error' : 'info',
                $failed ? 'Test failed' : 'Test completed',
                []
            );
        }

        ContextLogEmitter::emit(
            $store,
            $failed ? 500 : null,
            $failed || $store->hasErrorEvents() ? 'Test failed' : 'Test completed'
        );
        $store->clear();
    }

    private static function store(): ?ContextStore
    {
        if (! function_exists('app')) {
            return null;
        }

        try {
            if (! app()->bound(ContextStore::class)) {
                return null;
            }

            return app(ContextStore::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
