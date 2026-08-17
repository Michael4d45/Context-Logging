<?php

namespace Michael4d45\ContextLogging\Middleware;

use Closure;
use Illuminate\Http\Request;
use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\LoggingHelper;
use Michael4d45\ContextLogging\Profiling\SpxLifecycle;
use Illuminate\Support\Str;

/**
 * Request Context Middleware (Ingress).
 *
 * Seeds baseline request metadata and establishes correlation identifiers.
 * When request logging is enabled, adds an "Incoming Request" event with
 * masked headers and cookies (unless the route is ignored). Body and query
 * are included only when non-empty. Method/url/ip remain on outer context.
 */
class RequestContextMiddleware
{
    protected ContextStore $contextStore;

    public function __construct(ContextStore $contextStore)
    {
        $this->contextStore = ContextStore::shared($contextStore);
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Merge buffered web-only pre-lifecycle events (e.g. route files, channels) into this request.
        $this->contextStore->initialize(true);
        SpxLifecycle::startIfEnabled();

        if (LoggingHelper::shouldIgnoreRoute($request)) {
            $this->contextStore->suppressEmission();
        }

        // Generate a unique request ID if not already present
        $requestId = $request->header('X-Request-ID') ?: (string) Str::uuid();

        // Populate baseline request metadata
        $this->contextStore->addContexts([
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'full_url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'timestamp' => now()->toISOString(),
        ]);

        // Add authenticated user information if available
        if ($request->user()) {
            $this->contextStore->addContext('user_id', $request->user()->getKey());
        }

        $correlationHeaders = ['x-request-id', 'x-correlation-id', 'x-trace-id'];
        foreach ($correlationHeaders as $header) {
            if ($request->hasHeader($header)) {
                $this->contextStore->addContext(str_replace('-', '_', $header), $request->header($header));
            }
        }

        // Optional ingress event (method/url/ip already on outer context). Always emit when
        // enabled so timelines stay symmetric with Outgoing Response; omit empty payload keys.
        if (
            config('context-logging.log.request', false)
            && !LoggingHelper::shouldIgnoreRoute($request)
        ) {
            $payload = [
                'headers' => LoggingHelper::maskHeaders($request->headers->all()),
                'cookies' => LoggingHelper::maskCookies($request->cookies->all()),
                'timestamp' => now()->toISOString(),
            ];

            $body = LoggingHelper::maskSensitiveData($request->all());
            $query = LoggingHelper::maskSensitiveData($request->query());

            if ($body !== []) {
                $payload['body'] = $body;
            }
            $livewireActions = $this->livewireActions($request);
            if ($livewireActions !== []) {
                $payload['livewire_actions'] = $livewireActions;
            }
            if ($query !== []) {
                $payload['query_params'] = $query;
            }

            $this->contextStore->addEvent('info', 'Incoming Request', $payload);
        }

        $response = $next($request);
        $this->captureRouteContext($request);

        return $response;
    }

    /**
     * Record Laravel route name and handler after routing, so the explorer can
     * show a clickable code path. Livewire/Filament update payloads replace a
     * generic Livewire controller with the actual component method.
     */
    private function captureRouteContext(Request $request): void
    {
        $route = $request->route();
        if ($route !== null) {
            $name = $route->getName();
            if (is_string($name) && $name !== '') {
                $this->contextStore->addContext('route_name', $name);
            }

            $action = $route->getActionName();
            if (is_string($action) && $action !== '') {
                $this->contextStore->addContext('action', $action);
            }
        }

        $livewire = $this->livewireActions($request);
        $first = $livewire[0] ?? null;
        if (! is_array($first)) {
            return;
        }

        $component = is_string($first['component'] ?? null) ? $first['component'] : null;
        if ($component === null || $component === '') {
            return;
        }

        $method = is_string($first['method'] ?? null) ? $first['method'] : null;
        $this->contextStore->addContext(
            'action',
            $method !== null && $method !== '' ? $component.'@'.$method : $component,
        );
    }

    /**
     * Preserve a shallow, source-navigable summary before Monolog truncates
     * Livewire's deeply nested component payload.
     *
     * @return list<array{
     *     component: string,
     *     component_id: string|null,
     *     method: string|null,
     *     action: string|null
     * }>
     */
    private function livewireActions(Request $request): array
    {
        if (
            ! $request->headers->has('X-Livewire')
            && ! preg_match('#(?:^|/)livewire(?:-[^/]+)?/update$#', $request->path())
        ) {
            return [];
        }

        $components = $request->input('components', []);
        if (! is_array($components)) {
            return [];
        }

        $actions = [];
        foreach (array_slice($components, 0, 20) as $componentPayload) {
            if (! is_array($componentPayload)) {
                continue;
            }

            $snapshot = $componentPayload['snapshot'] ?? null;
            $snapshotData = is_string($snapshot) ? json_decode($snapshot, true) : $snapshot;
            $memo = is_array($snapshotData) && is_array($snapshotData['memo'] ?? null)
                ? $snapshotData['memo']
                : [];
            $component = is_string($memo['name'] ?? null) ? $memo['name'] : null;
            if ($component === null || $component === '') {
                continue;
            }

            $componentId = is_string($memo['id'] ?? null) ? $memo['id'] : null;
            $calls = is_array($componentPayload['calls'] ?? null) ? $componentPayload['calls'] : [];

            if ($calls === []) {
                $actions[] = [
                    'component' => $component,
                    'component_id' => $componentId,
                    'method' => null,
                    'action' => null,
                ];
                continue;
            }

            foreach (array_slice($calls, 0, 20) as $call) {
                if (! is_array($call)) {
                    continue;
                }

                $method = is_string($call['method'] ?? null) ? $call['method'] : null;
                $params = is_array($call['params'] ?? null) ? $call['params'] : [];

                $actions[] = [
                    'component' => $component,
                    'component_id' => $componentId,
                    'method' => $method,
                    'action' => $this->summarizeLivewireAction($method, $params),
                ];
            }
        }

        return $actions;
    }

    /**
     * Turn Livewire call params into a short, human label.
     *
     * Keeps Filament/Livewire action names (e.g. "Approve Order") and summarizes
     * opaque payloads like __lazyLoad's base64 mount data. Drops junk that would
     * otherwise inflate explorer chips.
     *
     * @param  list<mixed>  $params
     */
    private function summarizeLivewireAction(?string $method, array $params): ?string
    {
        if ($params === []) {
            return null;
        }

        $first = $params[0];

        if (is_array($first)) {
            return $this->summarizeDecodedLivewirePayload($method, $first);
        }

        if (! is_scalar($first)) {
            return null;
        }

        $value = trim((string) $first);
        if ($value === '') {
            return null;
        }

        if ($this->isHumanLivewireActionLabel($value)) {
            return $value;
        }

        $decoded = $this->decodeLivewirePayload($value);
        if ($decoded !== null) {
            return $this->summarizeDecodedLivewirePayload($method, $decoded);
        }

        return null;
    }

    private function isHumanLivewireActionLabel(string $value): bool
    {
        $length = strlen($value);
        if ($length === 0 || $length > 80) {
            return false;
        }

        // Base64-looking blobs (e.g. Livewire/Filament lazy-mount payloads).
        if ($length > 24 && preg_match('/^[A-Za-z0-9+\/_-]+=*$/', $value) === 1) {
            return false;
        }

        if ($value[0] === '{' || $value[0] === '[') {
            return false;
        }

        return preg_match('/^[\w\s.\-:\/#]+$/u', $value) === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeLivewirePayload(string $value): ?array
    {
        if ($value !== '' && ($value[0] === '{' || $value[0] === '[')) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        if (preg_match('/^[A-Za-z0-9+\/_-]+=*$/', $value) !== 1 || strlen($value) < 16) {
            return null;
        }

        $json = base64_decode(strtr($value, '-_', '+/'), true);
        if ($json === false || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<mixed>  $data
     */
    private function summarizeDecodedLivewirePayload(?string $method, array $data): ?string
    {
        // Filament relation-manager / lazy component mount payload.
        $forMount = $data['data']['forMount'][0] ?? null;
        if (is_array($forMount)) {
            $owner = $forMount['ownerRecord'][1] ?? null;
            if (is_array($owner) && is_string($owner['class'] ?? null)) {
                $short = class_basename($owner['class']);
                $key = $owner['key'] ?? null;

                return is_scalar($key) && (string) $key !== ''
                    ? $short.'#'.$key
                    : $short;
            }

            if (is_string($forMount['pageClass'] ?? null)) {
                return class_basename($forMount['pageClass']);
            }
        }

        foreach (['name', 'action', 'label', 'event'] as $key) {
            $candidate = $data[$key] ?? null;
            if (is_string($candidate) && $this->isHumanLivewireActionLabel($candidate)) {
                return $candidate;
            }
        }

        // Nested Filament action params: [['edit'], ...] or ['edit', ...]
        if (isset($data[0]) && is_string($data[0]) && $this->isHumanLivewireActionLabel($data[0])) {
            return $data[0];
        }

        if ($method !== null && str_starts_with($method, '__')) {
            return null;
        }

        return null;
    }
}
