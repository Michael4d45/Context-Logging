<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Context Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for the contextual logging package.
    |
    */

    'http' => [
        // Disable this to turn off outbound HTTP auto-capture and sub-context APIs.
        'enabled' => env('CONTEXT_LOG_HTTP_ENABLED', false),

        // Transparent GuzzleHttp\Client constructor patch (sidecar / zero app changes).
        // The Composer plugin re-applies this on every dump-autoload when allow-plugins
        // includes michael4d45/context-logging. Falls back to Http::globalMiddleware if
        // the patch is missing. When the patch is active, facade middleware is skipped.
        'guzzle_patch' => env('CONTEXT_LOG_HTTP_GUZZLE_PATCH', false),

        // Bind GuzzleHttp\Client in the container to an instrumented factory (DI only; does not
        // affect `new Client()` — use guzzle_patch for that).
        'guzzle_binding' => env('CONTEXT_LOG_HTTP_GUZZLE_BINDING', false),

        // Capture options for global outbound HTTP instrumentation.
        'capture_headers' => env('CONTEXT_LOG_HTTP_CAPTURE_HEADERS', false),
        'capture_body' => env('CONTEXT_LOG_HTTP_CAPTURE_BODY', false),

        // Header names to redact in custom hooks and request/response logging.
        'redact_headers' => [
            'authorization',
            'cookie',
            'set-cookie',
            'x-api-key',
        ],

        // Redaction token used for masked headers/body fields.
        'redact_value' => '[redacted]',

        // JSON body keys to redact recursively when capture_body or request/response logging is enabled.
        'redact_body_fields' => [
            'password',
            'token',
            'secret',
            'access_token',
            'refresh_token',
            'api_key',
            'client_secret',
        ],

        // Query string keys to redact recursively.
        'redact_query_params' => [
            'token',
            'access_token',
            'refresh_token',
            'api_key',
            'signature',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Incoming Request / Response Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, request and response details are added as events to the
    | context log (only for web requests; ignored in console). Incoming Request
    | is always added when log.request is true (empty body/query keys omitted).
    | Response and user logging run in terminating middleware.
    |
    */

    'log' => [
        'console' => env('CONTEXT_LOG_CONSOLE', false),
        'request' => env('CONTEXT_LOG_REQUEST', false),
        'response' => env('CONTEXT_LOG_RESPONSE', false),
        'user' => env('CONTEXT_LOG_USER', false),

        // User model attributes to include in the User log event when log.user is true. Use 'id' for the model key (getKey()).
        'user_attributes' => ['id', 'name', 'email'],

        'db' => env('CONTEXT_LOG_DB', false),
        'cache' => env('CONTEXT_LOG_CACHE', false),
        'queue' => env('CONTEXT_LOG_QUEUE', false),
        'mail' => env('CONTEXT_LOG_MAIL', false),
        'reverb' => env('CONTEXT_LOG_REVERB', false),
        'schedule' => env('CONTEXT_LOG_SCHEDULE', false),
        'notifications' => env('CONTEXT_LOG_NOTIFICATIONS', false),
        'broadcasting' => env('CONTEXT_LOG_BROADCASTING', false),

        // Route names or path patterns to exclude from request/response logging (e.g. 'health', 'horizon.*', 'livewire/*').
        'ignore_routes' => array_filter(explode(',', env('CONTEXT_LOG_IGNORE_ROUTES', ''))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database / SQL Result Capture
    |--------------------------------------------------------------------------
    |
    | When log.db is true, SQL text + timing are logged via DB::listen.
    | Result capture is opt-in and off by default: when false, no Connection
    | wrapping occurs (zero effect beyond the listen listener).
    |
    | When capture_results is true, Connection::resolverFor installs thin
    | capturing driver subclasses (same idea as the Guzzle Client wrap) so
    | SELECT rows / affected_rows can be attached to sql events.
    |
    */

    'database' => [
        'capture_results' => env('CONTEXT_LOG_DB_CAPTURE_RESULTS', false),

        'max_rows' => (int) env('CONTEXT_LOG_DB_MAX_ROWS', 20),
        'max_column_length' => (int) env('CONTEXT_LOG_DB_MAX_COLUMN_LENGTH', 500),
        'max_payload_bytes' => (int) env('CONTEXT_LOG_DB_MAX_PAYLOAD_BYTES', 65536),

        'redact_fields' => [
            'password',
            'token',
            'secret',
            'access_token',
            'refresh_token',
            'api_key',
            'client_secret',
            'remember_token',
        ],

        'redact_value' => env('CONTEXT_LOG_DB_REDACT_VALUE', '[redacted]'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Console Command Context
    |--------------------------------------------------------------------------
    |
    | Commands listed here do not get wrapped in a single "Console run completed"
    | context log. Use for long-running workers (e.g. queue:work, horizon) so
    | each job/unit of work is logged separately instead of one giant entry.
    | Supports Laravel wildcards (e.g. 'queue:*', 'horizon'). Tinker is always
    | skipped from command-wide wrapping so it can emit once per evaluation.
    |
    */

    'console' => [
        'skip_commands' => array_filter(array_merge(
            [
                'queue:work',
                'queue:listen',
                'horizon',
                'horizon:*',
                // PHPUnit owns per-test lifecycles via ContextLoggingExtension.
                'test',
                'test:*',
                'phpunit',
            ],
            explode(',', env('CONTEXT_LOG_SKIP_COMMANDS', '')),
        )),
    ],

    /*
    |--------------------------------------------------------------------------
    | PHPUnit Per-Test Context
    |--------------------------------------------------------------------------
    |
    | When enabled (CONTEXT_LOG_PHPUNIT=true) and ContextLoggingExtension is
    | registered in phpunit.xml, each test gets its own wide event — same idea
    | as Tinker emitting once per evaluation. HTTP tests that already emit via
    | middleware are not double-logged.
    |
    */

    'phpunit' => [
        'enabled' => filter_var(env('CONTEXT_LOG_PHPUNIT', false), FILTER_VALIDATE_BOOL),
        // Emit a stub wide event even when the test logged nothing.
        'emit_empty' => filter_var(env('CONTEXT_LOG_PHPUNIT_EMIT_EMPTY', true), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | log:monitor Display
    |--------------------------------------------------------------------------
    |
    | Character limits for request/response bodies shown by the log:monitor
    | command. 0 = no limit. CLI options --request-body-limit and
    | --response-body-limit override these values when provided.
    |
    | When request_body_limit_only / response_body_limit_only flags are enabled,
    | the corresponding body limit is applied only to bodies that start with
    | <!DOCTYPE html> or { (JSON object). If both flags are false, the limit
    | applies to all bodies whenever the limit is greater than 0.
    |
    */

    'monitor' => [
        'request_body_limit' => (int) env('CONTEXT_LOG_MONITOR_REQUEST_BODY_LIMIT', 0),
        'response_body_limit' => (int) env('CONTEXT_LOG_MONITOR_RESPONSE_BODY_LIMIT', 0),

        'request_body_limit_only' => [
            'doctype_html' => filter_var(env('CONTEXT_LOG_MONITOR_REQUEST_LIMIT_DOCTYPE_HTML', false), FILTER_VALIDATE_BOOL),
            'json_object' => filter_var(env('CONTEXT_LOG_MONITOR_REQUEST_LIMIT_JSON_OBJECT', false), FILTER_VALIDATE_BOOL),
        ],

        'response_body_limit_only' => [
            'doctype_html' => filter_var(env('CONTEXT_LOG_MONITOR_RESPONSE_LIMIT_DOCTYPE_HTML', false), FILTER_VALIDATE_BOOL),
            'json_object' => filter_var(env('CONTEXT_LOG_MONITOR_RESPONSE_LIMIT_JSON_OBJECT', false), FILTER_VALIDATE_BOOL),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Collapsed Trace Filtering
    |--------------------------------------------------------------------------
    |
    | Directories omitted from collapsed call-site traces (SQL, Log, Sentry,
    | etc.). Relative entries are resolved under base_path() (e.g. "vendor").
    | Absolute entries match as-is (e.g. Docker sidecar vendor trees).
    | Extra paths: CONTEXT_LOG_TRACE_IGNORE_PATHS (comma-separated).
    |
    */

    'trace' => [
        'ignore_paths' => array_values(array_filter(array_merge(
            [
                'vendor',
            ],
            explode(',', (string) env('CONTEXT_LOG_TRACE_IGNORE_PATHS', '')),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Profiling
    |--------------------------------------------------------------------------
    |
    | Local-dev oriented: attach collapsed call-site traces on Log::* events
    | (same shape as SQL events) and correlate active native profilers onto
    | the wide-event outer context as join keys (not flamegraph payloads).
    |
    */

    'profiling' => [
        'log_traces' => filter_var(env('CONTEXT_LOG_PROFILING_LOG_TRACES', true), FILTER_VALIDATE_BOOL),
        'log_trace_min_level' => env('CONTEXT_LOG_PROFILING_LOG_TRACE_MIN_LEVEL', 'debug'),
        'correlate' => filter_var(env('CONTEXT_LOG_PROFILING_CORRELATE', true), FILTER_VALIDATE_BOOL),
        'adapters' => ['spx', 'xdebug', 'blackfire'],
        'spx' => [
            'ui_base_url' => env('CONTEXT_LOG_SPX_UI_BASE_URL', ''),
            // When true, start SPX on every request (local-dev convenience; no cookie needed).
            'auto_enable' => filter_var(env('CONTEXT_LOG_SPX_AUTO_ENABLE', false), FILTER_VALIDATE_BOOL),
            // Must match spx.http_key in php.ini (SPX control panel / report auth).
            'http_key' => env('CONTEXT_LOG_SPX_HTTP_KEY', 'dev'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sentry Bridge
    |--------------------------------------------------------------------------
    |
    | When the Sentry PHP SDK is installed, optionally capture prepared Sentry
    | events (including Sentry\captureException()) into the active ContextStore
    | via before_send. Local apps can drop transport so Spotlight/DSN receive
    | nothing and Context Logging becomes the local error viewer.
    |
    | Defaults on for APP_ENV=local so Docker sidecars work without app edits.
    |
    */

    'sentry' => [
        'enabled' => filter_var(
            env('CONTEXT_LOG_SENTRY', env('APP_ENV') === 'local'),
            FILTER_VALIDATE_BOOL
        ),
        // When true, before_send returns null so Spotlight / cloud DSN get nothing.
        'drop' => filter_var(env('CONTEXT_LOG_SENTRY_DROP', true), FILTER_VALIDATE_BOOL),
        'capture_transactions' => filter_var(env('CONTEXT_LOG_SENTRY_TRANSACTIONS', false), FILTER_VALIDATE_BOOL),
        'max_breadcrumbs' => (int) env('CONTEXT_LOG_SENTRY_MAX_BREADCRUMBS', 20),
        'max_frames' => (int) env('CONTEXT_LOG_SENTRY_MAX_FRAMES', 40),
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram Bridge
    |--------------------------------------------------------------------------
    |
    | Capture Laravel notification channel "telegram" sends into the
    | ContextStore. When drop=true, NotificationSending returns false so
    | nothing is posted to Telegram — local apps can inspect alerts without
    | bot traffic.
    |
    | Apps that no-op Telegram outside production still need notifications
    | dispatched so this package can capture and optionally drop them.
    | Defaults on for APP_ENV=local (same pattern as the Sentry bridge).
    |
    */

    'telegram' => [
        'enabled' => filter_var(
            env('CONTEXT_LOG_TELEGRAM', env('APP_ENV') === 'local'),
            FILTER_VALIDATE_BOOL
        ),
        // When true, cancel the Telegram notification after capturing it.
        'drop' => filter_var(env('CONTEXT_LOG_TELEGRAM_DROP', true), FILTER_VALIDATE_BOOL),
    ],

];