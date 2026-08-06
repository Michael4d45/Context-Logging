<?php

namespace Michael4d45\ContextLogging;

use Illuminate\Support\Str;
use Michael4d45\ContextLogging\Profiling\ProfilerEventMarker;
use Michael4d45\ContextLogging\Profiling\SpxLifecycle;
use Michael4d45\ContextLogging\Support\TraceHelper;

/**
 * Context Store for accumulating request-wide metadata and log events.
 *
 * This is a process-wide singleton that accumulates contextual information for an
 * active HTTP / job / console lifecycle. Pre-lifecycle events are either buffered
 * until the HTTP request lifecycle starts (typical web SAPI) or emitted immediately
 * when running in the console (queue workers, Artisan, PHPUnit) so idle work is not
 * merged into unrelated lifecycles.
 */
class ContextStore
{
    /**
     * Request-wide metadata.
     */
    protected array $context = [];

    /**
     * Accumulated log events.
     */
    protected array $events = [];

    /**
     * Events recorded before a lifecycle exists: buffered for HTTP, or pending drain as standalone logs in the console.
     *
     * @var list<array{level: string, message: string, context: array, timestamp: float}>
     */
    protected array $preLifecycleQueue = [];

    /**
     * True while draining {@see $preLifecycleQueue} so nested addEvent appends to the queue.
     */
    protected bool $drainingPreLifecycle = false;

    /**
     * Monotonic marker sequence for native-profiler event correlation.
     */
    protected int $profileMarkerSequence = 0;

    /**
     * Start timestamp for duration calculation.
     */
    protected ?float $startTime = null;

    /**
     * Accumulated outbound HTTP request/response sub-context.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $httpCalls = [];

    /**
     * Controls whether outbound HTTP sub-context tracking is active.
     */
    protected bool $httpEnabled = true;

    /**
     * Whether a request/job/command lifecycle is currently active.
     */
    protected bool $lifecycleStarted = false;

    /**
     * Whether the current lifecycle has already been emitted.
     */
    protected bool $emitted = false;

    /**
     * Whether emission should be suppressed for the current lifecycle.
     */
    protected bool $emissionSuppressed = false;

    /**
     * Fingerprints of exceptions already recorded this lifecycle (dedupe).
     *
     * @var array<string, true>
     */
    protected array $capturedExceptionFingerprints = [];

    public function __construct(
        protected ?HttpContextHookRunner $httpHookRunner = null,
        ?bool $httpEnabled = null,
    ) {
        if ($httpEnabled !== null) {
            $this->httpEnabled = $httpEnabled;
        }
    }

    /**
     * Resolve the process-wide ContextStore, binding the first concrete instance
     * when the service provider has not registered a singleton (e.g. middleware
     * registered in tests without loading ContextLoggingServiceProvider).
     *
     * When a caller passes an explicit store that is not the container binding
     * (typical in unit tests: `new Middleware($customStore)`), that instance is
     * kept so tests can inject subclasses.
     */
    public static function shared(?self $resolved = null): self
    {
        if (! function_exists('app')) {
            return $resolved ?? new self;
        }

        try {
            $app = app();
        } catch (\Throwable) {
            return $resolved ?? new self;
        }

        if (! $app->bound(self::class)) {
            $store = $resolved ?? new self(
                $app->bound(HttpContextHookRunner::class) ? $app->make(HttpContextHookRunner::class) : null,
                (bool) $app['config']->get('context-logging.http.enabled', true),
            );

            $app->instance(self::class, $store);

            return $store;
        }

        $bound = $app->make(self::class);

        if ($resolved !== null && $resolved !== $bound) {
            return $resolved;
        }

        return $bound;
    }

    /**
     * Initialize the context store for a new request, job, or command lifecycle.
     *
     * @param  bool  $promotePreLifecycleEvents  When true (HTTP middleware), move buffered pre-lifecycle events into this lifecycle. When false, emit any queued events as standalone logs first, then start with an empty event list.
     */
    public function initialize(bool $promotePreLifecycleEvents = false): void
    {
        if ($promotePreLifecycleEvents) {
            $this->events = $this->preLifecycleQueue;
            $this->preLifecycleQueue = [];
        } else {
            if ($this->preLifecycleQueue !== []) {
                $this->drainPreLifecycleQueue();
            }
            $this->events = [];
        }

        $this->context = [];
        $this->httpCalls = [];
        $this->profileMarkerSequence = 0;
        $this->capturedExceptionFingerprints = [];
        $this->startTime = microtime(true);
        $this->lifecycleStarted = true;
        $this->emitted = false;
        $this->emissionSuppressed = false;
    }

    /**
     * Add request metadata to the context.
     */
    public function addContext(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }

    /**
     * Add multiple context items at once.
     */
    public function addContexts(array $contexts): void
    {
        $this->context = array_merge($this->context, $contexts);
    }

    /**
     * Get a context value.
     */
    public function getContext(string $key): mixed
    {
        return $this->context[$key] ?? null;
    }

    /**
     * Check if HTTP call tracking is enabled.
     */
    public function isHttpEnabled(): bool
    {
        return $this->httpEnabled;
    }

    /**
     * Get all context data.
     */
    public function getAllContext(): array
    {
        return $this->context;
    }

    /**
     * Record a Throwable as an error event (deduped per lifecycle).
     *
     * @return bool True when a new event was added
     */
    public function addException(\Throwable $e, string $level = 'error'): bool
    {
        $fingerprint = md5($e::class.'|'.$e->getMessage().'|'.$e->getFile().'|'.$e->getLine());
        if (isset($this->capturedExceptionFingerprints[$fingerprint])) {
            return false;
        }
        $this->capturedExceptionFingerprints[$fingerprint] = true;

        $root = $e;
        while ($root->getPrevious() instanceof \Throwable) {
            $root = $root->getPrevious();
        }

        $display = $e->getMessage() !== '' ? $e->getMessage() : $e::class;
        if ($root !== $e && $root->getMessage() !== '') {
            // Prefer the underlying cause (e.g. RouteNotFound inside ViewException).
            $display = $root->getMessage();
        }

        $context = [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => TraceHelper::relativePath($e->getFile()),
            'line' => $e->getLine(),
        ];

        if ($root !== $e) {
            $context['previous'] = [
                'exception' => $root::class,
                'message' => $root->getMessage(),
                'file' => TraceHelper::relativePath($root->getFile()),
                'line' => $root->getLine(),
            ];
        }

        if ((bool) config('context-logging.profiling.log_traces', true)) {
            $context['trace'] = TraceHelper::getCollapsedExceptionTrace($e);
        }

        $this->addEvent($level, $display, $context);

        return true;
    }

    /**
     * Whether this lifecycle already has an error/critical/alert/emergency event.
     */
    public function hasErrorEvents(): bool
    {
        foreach ($this->events as $event) {
            if (in_array($event['level'] ?? '', ['error', 'critical', 'alert', 'emergency'], true)) {
                return true;
            }
        }

        foreach ($this->preLifecycleQueue as $event) {
            if (in_array($event['level'] ?? '', ['error', 'critical', 'alert', 'emergency'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add a log event annotation.
     */
    public function addEvent(string $level, string $message, array $context = []): void
    {
        if (SpxLifecycle::isStarted()) {
            $profileMarkerSequence = ++$this->profileMarkerSequence;
            ProfilerEventMarker::point($profileMarkerSequence);
            $context['profile_marker_seq'] = $profileMarkerSequence;
        }

        $event = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'timestamp' => microtime(true),
        ];

        if ($this->lifecycleStarted) {
            $this->events[] = $event;
            return;
        }

        $this->preLifecycleQueue[] = $event;

        if ($this->shouldBufferPreLifecycleEvents()) {
            return;
        }

        if (!$this->drainingPreLifecycle) {
            $this->drainPreLifecycleQueue();
        }
    }

    /**
     * Web requests buffer pre-lifecycle instrumentation so it can merge into the request-wide log.
     * Console processes (queue, Artisan, tests) emit immediately so unrelated activity is not deferred.
     */
    protected function shouldBufferPreLifecycleEvents(): bool
    {
        if (!function_exists('app')) {
            return false;
        }

        try {
            return !app()->runningInConsole();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @internal
     */
    protected function drainPreLifecycleQueue(): void
    {
        $this->drainingPreLifecycle = true;
        try {
            while ($this->preLifecycleQueue !== []) {
                $next = array_shift($this->preLifecycleQueue);
                ContextLogEmitter::emitStandaloneEvent($next);
            }
        } finally {
            $this->drainingPreLifecycle = false;
        }
    }

    /**
     * Get all accumulated events.
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * Get events queued before lifecycle initialization (HTTP buffer until middleware runs).
     *
     * @return list<array{level: string, message: string, context: array, timestamp: float}>
     */
    public function getBufferedEvents(): array
    {
        return $this->preLifecycleQueue;
    }

    /**
     * Check if the context store has any events.
     */
    public function hasEvents(): bool
    {
        return $this->events !== [] || $this->preLifecycleQueue !== [];
    }

    /**
     * Check whether a request/job/command lifecycle is active.
     */
    public function hasLifecycleStarted(): bool
    {
        return $this->lifecycleStarted;
    }

    /**
     * Mark the current lifecycle as emitted.
     */
    public function markEmitted(): void
    {
        $this->emitted = true;
    }

    /**
     * Determine whether the current lifecycle has already been emitted.
     */
    public function hasBeenEmitted(): bool
    {
        return $this->emitted;
    }

    /**
     * Suppress or re-enable emission for the current lifecycle.
     */
    public function suppressEmission(bool $suppressed = true): void
    {
        $this->emissionSuppressed = $suppressed;
    }

    /**
     * Determine whether emission is suppressed for the current lifecycle.
     */
    public function isEmissionSuppressed(): bool
    {
        return $this->emissionSuppressed;
    }

    /**
     * Finalize the context with request completion data.
     */
    public function finalize(?int $statusCode = null): void
    {
        if ($this->startTime !== null) {
            $this->context['duration_ms'] = round((microtime(true) - $this->startTime) * 1000, 2);
        }

        if ($statusCode !== null) {
            $this->context['status'] = $statusCode;
        }
    }

    /**
     * Get the final structured payload for logging.
     */
    public function getPayload(): array
    {
        $combinedEvents = $this->events;

        // Add HTTP calls as single events
        foreach ($this->httpCalls as $httpCall) {
            $eventContext = [
                'http_call_id' => $httpCall['id'],
            ];
            
            $timestamp = null;
            
            if (isset($httpCall['request'])) {
                $eventContext['request'] = $httpCall['request'];
                $timestamp = $httpCall['request']['timestamp'];
            }
            
            if (isset($httpCall['response'])) {
                $eventContext['response'] = $httpCall['response'];
                if ($timestamp === null) {
                    $timestamp = $httpCall['response']['timestamp'];
                }
            }

            if (isset($httpCall['trace']) && is_array($httpCall['trace']) && $httpCall['trace'] !== []) {
                $eventContext['trace'] = $httpCall['trace'];
            }
            
            if ($timestamp !== null) {
                $combinedEvents[] = [
                    'level' => 'debug',
                    'message' => 'HTTP Call',
                    'context' => $eventContext,
                    'timestamp' => $timestamp,
                ];
            }
        }

        // Sort all events by timestamp
        usort($combinedEvents, function ($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        });

        return [
            'context' => $this->context,
            'events' => $combinedEvents,
        ];
    }

    /**
     * Clear all stored data.
     */
    public function clear(): void
    {
        $this->context = [];
        $this->events = [];
        $this->preLifecycleQueue = [];
        $this->httpCalls = [];
        $this->profileMarkerSequence = 0;
        $this->capturedExceptionFingerprints = [];
        $this->startTime = null;
        $this->lifecycleStarted = false;
        $this->emitted = false;
        $this->emissionSuppressed = false;
    }

    /**
     * Begin tracking an outbound HTTP call and return the generated call ID.
     */
    public function beginHttpCall(array $request): string
    {
        if (!$this->httpEnabled) {
            return '';
        }

        $id = (string) Str::uuid();

        $normalizedRequest = array_merge($request, [
            'timestamp' => $request['timestamp'] ?? microtime(true),
        ]);

        if ($this->httpHookRunner !== null) {
            $normalizedRequest = $this->httpHookRunner->runBeforeRequest($normalizedRequest);
        }

        // Capture at request start (caller site), not on response completion.
        $this->httpCalls[$id] = [
            'id' => $id,
            'request' => $normalizedRequest,
            'context' => [],
            'trace' => TraceHelper::getCollapsedTrace(),
        ];

        return $id;
    }

    /**
     * Add extra sub-context for a tracked outbound HTTP call.
     */
    public function addHttpContext(string $id, array $extra): void
    {
        if (!$this->httpEnabled || $id === '') {
            return;
        }

        if (!isset($this->httpCalls[$id])) {
            $this->httpCalls[$id] = [
                'id' => $id,
                'request' => [],
                'context' => [],
            ];
        }

        $this->httpCalls[$id]['context'] = array_merge(
            $this->httpCalls[$id]['context'],
            $extra
        );
    }

    /**
     * Complete tracking for an outbound HTTP call by attaching response data.
     */
    public function completeHttpCall(string $id, array $response): void
    {
        if (!$this->httpEnabled || $id === '') {
            return;
        }

        if (!isset($this->httpCalls[$id])) {
            $this->httpCalls[$id] = [
                'id' => $id,
                'request' => [],
                'context' => [],
            ];
        }

        $normalizedResponse = array_merge($response, [
            'timestamp' => $response['timestamp'] ?? microtime(true),
        ]);

        $requestTimestamp = $this->httpCalls[$id]['request']['timestamp'] ?? null;

        if ($requestTimestamp !== null && !array_key_exists('duration_ms', $normalizedResponse)) {
            $normalizedResponse['duration_ms'] = round((microtime(true) - (float) $requestTimestamp) * 1000, 2);
        }

        if ($this->httpHookRunner !== null) {
            $normalizedResponse = $this->httpHookRunner->runAfterResponse(
                $this->httpCalls[$id]['request'],
                $normalizedResponse,
                $this->httpCalls[$id]['context'],
            );
        }

        $this->httpCalls[$id]['response'] = $normalizedResponse;
    }

    /**
     * Get tracked outbound HTTP calls.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHttpCalls(): array
    {
        return array_values($this->httpCalls);
    }
}
