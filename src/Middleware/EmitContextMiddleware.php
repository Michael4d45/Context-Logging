<?php

namespace Michael4d45\ContextLogging\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\ContextLogEmitter;
use Michael4d45\ContextLogging\LoggingHelper;

/**
 * Emit Context Middleware (Terminating).
 *
 * Finalizes request context, computes duration, and emits a single structured log entry.
 * When response/user logging is enabled, adds User and/or Outgoing Response events.
 * Ensures at least one event so the lifecycle is marked emitted and the shutdown
 * fallback does not log a false "Request interrupted" entry.
 */
class EmitContextMiddleware
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
        return $next($request);
    }

    /**
     * Handle the terminating middleware.
     */
    public function terminate(Request $request, Response $response): void
    {
        // Completely skip emitting any log for ignored routes.
        if (LoggingHelper::shouldIgnoreRoute($request)) {
            $this->contextStore->clear();
            return;
        }

        $logRequest = config('context-logging.log.request', false);
        $logResponse = config('context-logging.log.response', false);
        $logUser = config('context-logging.log.user', false);

        if ($logUser && $request->user()) {
            $user = $request->user();
            $attributes = config('context-logging.log.user_attributes', ['id', 'name', 'email']);
            $payload = [];
            foreach ($attributes as $key) {
                $payload[$key] = $key === 'id' ? $user->getKey() : ($user->{$key} ?? null);
            }
            $payload['timestamp'] = now()->toISOString();
            $this->contextStore->addEvent('info', 'User', $payload);
        }

        if ($logResponse) {
            $content = $response->getContent();
            $contentStr = is_string($content) ? $content : '';
            $log = [
                'status_code' => $response->getStatusCode(),
                'content_type' => $response->headers->get('content-type'),
                'headers' => LoggingHelper::maskHeaders($response->headers->all()),
                'content_length' => strlen($contentStr),
                'timestamp' => now()->toISOString(),
            ];
            if (self::isJsonResponse($response)) {
                $decoded = json_decode($contentStr, true);
                $log['body'] = is_array($decoded) ? LoggingHelper::maskSensitiveData($decoded) : $contentStr;
            } else {
                $log['body'] = $contentStr;
            }
            if ($response instanceof RedirectResponse) {
                $log['redirect_target'] = $response->getTargetUrl();
            }
            $this->contextStore->addEvent('info', 'Outgoing Response', $log);
        }

        if (!$this->contextStore->hasEvents()) {
            $this->contextStore->addEvent('info', 'Request completed', []);
        }

        ContextLogEmitter::emit($this->contextStore, $response->getStatusCode(), 'Request completed');
    }

    private static function isJsonResponse(Response $response): bool
    {
        if ($response instanceof JsonResponse) {
            return true;
        }

        $contentType = $response->headers->get('content-type');
        if ($contentType !== null && str_contains(strtolower($contentType), 'application/json')) {
            return true;
        }

        return false;
    }
}
