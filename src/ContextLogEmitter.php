<?php

namespace Michael4d45\ContextLogging;

use Illuminate\Log\LogManager;
use Michael4d45\ContextLogging\Profiling\ProfilerCorrelator;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;

/**
 * Emits the context store payload to the log.
 * Used by both HTTP middleware and console lifecycle.
 */
class ContextLogEmitter
{
    private const SEVERITY_LEVELS = [
        'emergency' => 0,
        'alert' => 1,
        'critical' => 2,
        'error' => 3,
        'warning' => 4,
        'notice' => 5,
        'info' => 6,
        'debug' => 7,
    ];

    public static function emit(
        ContextStore $contextStore,
        ?int $statusCode,
        string $message,
        ?ProfilerCorrelator $correlator = null,
    ): void {
        if ($contextStore->isEmissionSuppressed() || $contextStore->hasBeenEmitted()) {
            return;
        }

        $contextStore->finalize($statusCode);
        self::attachProfilerCorrelation($contextStore, $correlator);

        if (!$contextStore->hasEvents()) {
            $contextStore->markEmitted();

            return;
        }

        $payload = $contextStore->getPayload();
        $highestLevel = 'debug';
        $highestPriority = 7;

        foreach ($payload['events'] as $event) {
            $priority = self::SEVERITY_LEVELS[$event['level']] ?? 7;
            if ($priority < $highestPriority) {
                $highestPriority = $priority;
                $highestLevel = $event['level'];
            }
        }

        $contextStore->markEmitted();

        self::writeToLaravelLog($highestLevel, $message, $payload);
    }

    /**
     * Attach native profiler join keys to the outer context when correlation is enabled.
     */
    private static function attachProfilerCorrelation(
        ContextStore $contextStore,
        ?ProfilerCorrelator $correlator = null,
    ): void {
        try {
            $refs = ($correlator ?? new ProfilerCorrelator())->detect();
        } catch (\Throwable) {
            return;
        }

        if ($refs === []) {
            return;
        }

        $contextStore->addContext('profile', $refs[0]->toArray());

        if (count($refs) > 1) {
            $contextStore->addContext(
                'profiles',
                array_map(static fn ($ref) => $ref->toArray(), $refs),
            );
        }
    }

    /**
     * Emit a single event immediately when no request/job/command lifecycle is active.
     *
     * Used so cache, SQL, etc. are logged as they happen instead of accumulating
     * until the next lifecycle (e.g. queue worker idle polling).
     *
     * @param  array{level: string, message: string, context: array, timestamp: float}  $event
     */
    public static function emitStandaloneEvent(array $event): void
    {
        $payload = [
            'context' => [],
            'events' => [$event],
        ];

        self::writeToLaravelLog(
            $event['level'],
            self::standaloneLogMessage($event),
            $payload,
        );
    }

    /**
     * Write through a fresh LogManager so ContextualLogger is not re-entered.
     *
     * Ensures the common `json` wide-event channel exists when referenced by
     * LOG_STACK / LOG_CHANNEL (e.g. Docker/dev setups) even if the app did not
     * register it. Logging failures never propagate into the request lifecycle.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function writeToLaravelLog(string $level, string $message, array $payload): void
    {
        try {
            self::ensureJsonLogChannel();

            (new LogManager(app()))->log($level, $message, $payload);
        } catch (\Throwable) {
            // Logging must never break HTTP / console / job completion.
        }
    }

    /**
     * Register a JSON Monolog channel when missing so stack/default configs that
     * reference `json` (common with log:monitor) keep working.
     */
    public static function ensureJsonLogChannel(): void
    {
        if (! function_exists('config') || config('logging.channels.json') !== null) {
            return;
        }

        $path = function_exists('storage_path')
            ? storage_path('logs/laravel.log')
            : (sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel.log');

        config([
            'logging.channels.json' => [
                'driver' => 'monolog',
                'level' => env('LOG_LEVEL', 'debug'),
                'handler' => StreamHandler::class,
                'formatter' => JsonFormatter::class,
                'with' => [
                    'stream' => $path,
                ],
            ],
        ]);
    }

    /**
     * Prefer a human-readable outer message for idle/console instrumentation so
     * explorers are not stuck with opaque titles like "sql" or "Error".
     *
     * @param  array{level: string, message: string, context: array, timestamp: float}  $event
     */
    private static function standaloneLogMessage(array $event): string
    {
        $message = (string) ($event['message'] ?? 'log');
        $context = is_array($event['context'] ?? null) ? $event['context'] : [];

        if ($message === 'sql') {
            $sql = (string) ($context['SQL'] ?? $context['sql'] ?? $context['query'] ?? '');
            if ($sql !== '') {
                return self::truncateOneLine($sql, 96);
            }
        }

        $detail = $context['message'] ?? null;
        if (
            is_string($detail)
            && $detail !== ''
            && in_array(strtolower($message), ['error', 'exception', 'log'], true)
        ) {
            $prefix = $context['exception'] ?? null;
            if (is_string($prefix) && $prefix !== '' && $prefix !== $detail) {
                $short = str_contains($prefix, '\\')
                    ? substr($prefix, (int) strrpos($prefix, '\\') + 1)
                    : $prefix;

                return self::truncateOneLine("{$short}: {$detail}", 96);
            }

            return self::truncateOneLine($detail, 96);
        }

        return $message;
    }

    private static function truncateOneLine(string $text, int $max): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max).'…';
    }

    /**
     * Emit the current lifecycle during shutdown when normal termination did not run.
     *
     * @param array<string, mixed>|null $lastError
     */
    public static function emitInterruptedLifecycle(ContextStore $contextStore, ?array $lastError = null): void
    {
        if (
            !$contextStore->hasLifecycleStarted()
            || $contextStore->isEmissionSuppressed()
            || $contextStore->hasBeenEmitted()
        ) {
            return;
        }

        $isFatalError = self::isFatalError($lastError);

        if ($isFatalError) {
            $contextStore->addEvent('critical', 'PHP fatal error', [
                'type' => $lastError['type'] ?? null,
                'message' => $lastError['message'] ?? null,
                'file' => $lastError['file'] ?? null,
                'line' => $lastError['line'] ?? null,
            ]);
        } elseif (!$contextStore->hasEvents()) {
            $contextStore->addEvent('warning', self::defaultInterruptionEvent($contextStore), []);
        }

        self::emit(
            $contextStore,
            self::defaultInterruptedStatusCode($contextStore, $isFatalError),
            self::defaultInterruptedMessage($contextStore, $isFatalError)
        );
    }

    /**
     * @param array<string, mixed>|null $lastError
     */
    public static function isFatalError(?array $lastError): bool
    {
        if ($lastError === null) {
            return false;
        }

        return in_array((int) ($lastError['type'] ?? 0), [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
            E_RECOVERABLE_ERROR,
        ], true);
    }

    private static function defaultInterruptedMessage(ContextStore $contextStore, bool $isFatalError): string
    {
        if ($contextStore->getContext('source') === 'tinker') {
            return $isFatalError ? 'Tinker execution failed' : 'Tinker execution interrupted';
        }

        if ($contextStore->getContext('method') !== null) {
            return $isFatalError ? 'Request failed' : 'Request interrupted';
        }

        return $isFatalError ? 'Console run failed' : 'Console run interrupted';
    }

    private static function defaultInterruptionEvent(ContextStore $contextStore): string
    {
        if ($contextStore->getContext('source') === 'tinker') {
            return 'Tinker execution interrupted';
        }

        if ($contextStore->getContext('method') !== null) {
            return 'Request interrupted';
        }

        return 'Console run interrupted';
    }

    private static function defaultInterruptedStatusCode(ContextStore $contextStore, bool $isFatalError): ?int
    {
        if ($isFatalError && $contextStore->getContext('method') !== null) {
            return 500;
        }

        return null;
    }
}
