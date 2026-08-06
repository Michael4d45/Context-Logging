<?php

namespace Michael4d45\ContextLogging;

use Illuminate\Log\LogManager;
use Michael4d45\ContextLogging\Support\TraceHelper;

/**
 * Contextual Logger Implementation.
 *
 * Extends Laravel's LogManager but returns a contextual logger for logging.
 * All log calls become context annotations in the ContextStore.
 */
class ContextualLogger extends LogManager
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

    public function __construct(
        protected ContextStore $contextStore
    ) {
        // Call parent constructor with the application
        parent::__construct(app());
    }

    /**
     * Override logging methods to accumulate in context store
     */
    public function emergency($message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log($level, $message, array $context = []): void
    {
        $level = (string) $level;

        // Laravel's ExceptionHandler logs with context['exception'] => Throwable.
        // Record a structured exception event (deduped) instead of a raw log row
        // that may stringify poorly or omit class/file/line.
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $this->contextStore->addException($context['exception'], $level);

            return;
        }

        $context = $this->maybeAttachTrace($level, $context);

        // Only accumulate in context store for wide event (no pass-through)
        $this->contextStore->addEvent(
            $level,
            (string) $message,
            $context
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function maybeAttachTrace(string $level, array $context): array
    {
        if (array_key_exists('trace', $context)) {
            return $context;
        }

        if (! (bool) config('context-logging.profiling.log_traces', true)) {
            return $context;
        }

        if (! $this->levelMeetsMinimum($level)) {
            return $context;
        }

        $context['trace'] = TraceHelper::getCollapsedTrace();

        return $context;
    }

    private function levelMeetsMinimum(string $level): bool
    {
        $minLevel = strtolower((string) config('context-logging.profiling.log_trace_min_level', 'debug'));
        $eventPriority = self::SEVERITY_LEVELS[strtolower($level)] ?? 7;
        $minPriority = self::SEVERITY_LEVELS[$minLevel] ?? 7;

        // Lower number = more severe. Include levels at or above the minimum severity
        // (i.e. priority <= minPriority), matching PSR-3 ordering.
        return $eventPriority <= $minPriority;
    }
}
