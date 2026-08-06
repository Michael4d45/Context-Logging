<?php

namespace Michael4d45\ContextLogging;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Tinker\Console\TinkerCommand;
use Michael4d45\ContextLogging\Database\DatabaseInstrumentation;
use Michael4d45\ContextLogging\Profiling\SpxLifecycle;
use Michael4d45\ContextLogging\Sentry\SentryBridge;
use Michael4d45\ContextLogging\Support\TraceHelper;
use Michael4d45\ContextLogging\Telegram\TelegramBridge;

/**
 * Context Logging Service Provider.
 *
 * Registers bindings, middleware, and integrations for the contextual logging system.
 * Optionally registers DB, cache, and queue event listeners to add service events
 * to the same context log as HTTP request/response.
 */
class ContextLoggingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/context-logging.php',
            'context-logging'
        );

        $this->registerJsonLogChannel();

        $this->app->singleton(HttpContextHookRunner::class, function ($app) {
            return new HttpContextHookRunner();
        });

        // Register the ContextStore as a request-scoped singleton
        $this->app->singleton(ContextStore::class, function ($app) {
            return new ContextStore(
                $app->make(HttpContextHookRunner::class),
                (bool) $app['config']->get('context-logging.http.enabled', true),
            );
        });

        $this->app->singleton(HttpClientInstrumentation::class, function ($app) {
            return new HttpClientInstrumentation(
                $app->make(ContextStore::class),
            );
        });

        $this->app->singleton(DatabaseInstrumentation::class, function ($app) {
            return new DatabaseInstrumentation(
                $app->make(ContextStore::class),
            );
        });

        $this->app->singleton(SentryBridge::class, function ($app) {
            return new SentryBridge(
                $app->make(ContextStore::class),
            );
        });

        $this->app->singleton(TelegramBridge::class, function ($app) {
            return new TelegramBridge(
                $app->make(ContextStore::class),
            );
        });

        if ((bool) $this->app['config']->get('context-logging.http.guzzle_binding', false)
            && (bool) $this->app['config']->get('context-logging.http.enabled', false)) {
            $this->app->bind(\GuzzleHttp\Client::class, function ($app, array $parameters = []) {
                $config = [];

                if (isset($parameters['config']) && is_array($parameters['config'])) {
                    $config = $parameters['config'];
                } elseif ($parameters !== [] && array_is_list($parameters) && is_array($parameters[0] ?? null)) {
                    $config = $parameters[0];
                }

                return $app->make(HttpClientInstrumentation::class)->createClient($config);
            });
        }

        // Extend Laravel's logger to use contextual logging
        $this->app->extend('log', function ($originalLogger, $app) {
            return new ContextualLogger(
                $app->make(ContextStore::class)
            );
        });

        // Other providers may resolve Log during register()/boot() before this
        // extend runs. The Facade caches that root forever, so Illuminate\Log
        // calls would bypass ContextualLogger and write plain Monolog lines.
        $this->forgetCachedLogFacade();

        // Install capturing Connection resolvers before any provider boot() can
        // open a stock connection (otherwise result capture never attaches).
        $this->app->make(DatabaseInstrumentation::class)->register(resolversOnly: true);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration file (for future optional config)
        $this->publishes([
            __DIR__.'/../config/context-logging.php' => config_path('context-logging.php'),
        ], 'config');

        if ((bool) config('context-logging.http.enabled', true)) {
            $this->ensureGuzzlePatchInstalled();
            $this->app->make(HttpClientInstrumentation::class)->register();
        }

        if ($this->app->runningInConsole()) {
            $this->overrideTinkerCommand();
            $this->bootConsoleContext();
            $this->commands([
                Console\MonitorLogsCommand::class,
            ]);
        }

        $this->registerShutdownFallback();

        $this->bootDatabaseLogging();
        $this->bootCacheLogging();
        $this->bootQueueLogging();
        $this->bootMailLogging();
        $this->bootReverbLogging();
        $this->bootScheduleLogging();
        $this->bootNotificationsLogging();
        $this->bootBroadcastingLogging();
        $this->bootSentryBridge();
        $this->bootTelegramBridge();
        $this->bootExceptionCapture();

        // Clear again after all providers have registered/booted; a later
        // provider may have resolved Log between our register() and boot().
        $this->forgetCachedLogFacade();
        $this->app->booted(fn () => $this->forgetCachedLogFacade());

        // Note: Middleware registration is intentionally NOT automatic.
        // Users must manually register RequestContextMiddleware and EmitContextMiddleware
        // in their bootstrap/app.php file to have explicit control over middleware ordering.
    }

    /**
     * Capture reportable exceptions into the active context wide event.
     */
    protected function bootExceptionCapture(): void
    {
        $this->callAfterResolving(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            function ($handler): void {
                if (! is_object($handler) || ! method_exists($handler, 'reportable')) {
                    return;
                }

                $handler->reportable(function (\Throwable $e): void {
                    try {
                        $this->app->make(ContextStore::class)->addException($e);
                    } catch (\Throwable) {
                        // Never break exception reporting.
                    }
                });
            }
        );
    }

    protected function forgetCachedLogFacade(): void
    {
        if (class_exists(\Illuminate\Support\Facades\Log::class)) {
            \Illuminate\Support\Facades\Log::clearResolvedInstance('log');
        }

        if (class_exists(\Michael4d45\ContextLogging\Log::class)) {
            \Michael4d45\ContextLogging\Log::clearResolvedInstance('log');
        }
    }

    /**
     * Ensure a JSON Monolog channel exists for wide-event output (log:monitor).
     * Apps may already define it; Docker/dev often sets LOG_STACK=json.
     */
    protected function registerJsonLogChannel(): void
    {
        ContextLogEmitter::ensureJsonLogChannel();
    }

    protected function bootSentryBridge(): void
    {
        if (! (bool) config('context-logging.sentry.enabled', false)) {
            return;
        }

        $this->app->make(SentryBridge::class)->register();
    }

    protected function bootTelegramBridge(): void
    {
        if (! (bool) config('context-logging.telegram.enabled', false)) {
            return;
        }

        $this->app->make(TelegramBridge::class)->register();
    }

    /**
     * Re-apply the Guzzle Client classmap patch when configured but missing
     * (e.g. after composer dump-autoload), or when Generated/UnpatchedClient
     * no longer matches the app's guzzlehttp/guzzle Client.php.
     */
    protected function ensureGuzzlePatchInstalled(): void
    {
        if (! (bool) config('context-logging.http.guzzle_patch', false)) {
            return;
        }

        $installer = new \Michael4d45\ContextLogging\Guzzle\PatchInstaller;

        $patched = \Michael4d45\ContextLogging\Guzzle\ClientPatch::isClientPatched();
        $stale = false;

        try {
            $stale = $installer->isGeneratedStale();
        } catch (\Throwable) {
            $stale = true;
        }

        if ($patched && ! $stale) {
            return;
        }

        try {
            $installer->install(preAutoloadDump: false);
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                try {
                    logger()->warning('context-logging: failed to auto-install Guzzle Client patch: '.$e->getMessage());
                } catch (\Throwable) {
                    // Ignore.
                }
            }
        }
    }

    protected function bootDatabaseLogging(): void
    {
        $this->app->make(DatabaseInstrumentation::class)->register();
    }

    protected function bootCacheLogging(): void
    {
        if (!config('context-logging.log.cache', false)) {
            return;
        }

        $addCacheEvent = function (string $eventName, array $context): void {
            $this->app->make(ContextStore::class)->addEvent('debug', 'cache', array_merge(
                ['event' => $eventName, 'trace' => TraceHelper::getCollapsedTrace()],
                $context
            ));
        };

        Event::listen(CacheHit::class, function (CacheHit $event) use ($addCacheEvent): void {
            $addCacheEvent('CacheHit', ['key' => $event->key]);
        });

        Event::listen(CacheMissed::class, function (CacheMissed $event) use ($addCacheEvent): void {
            $addCacheEvent('CacheMissed', ['key' => $event->key]);
        });

        Event::listen(KeyWritten::class, function (KeyWritten $event) use ($addCacheEvent): void {
            $addCacheEvent('KeyWritten', ['key' => $event->key, 'expiration' => $event->seconds]);
        });

        Event::listen(KeyForgotten::class, function (KeyForgotten $event) use ($addCacheEvent): void {
            $addCacheEvent('KeyForgotten', ['key' => $event->key]);
        });
    }

    protected function bootQueueLogging(): void
    {
        if (!config('context-logging.log.queue', false)) {
            return;
        }

        $store = fn () => $this->app->make(ContextStore::class);
        $trace = fn () => TraceHelper::getCollapsedTrace();

        Event::listen(JobQueued::class, function (JobQueued $event) use ($store, $trace): void {
            $job = $event->job;
            $jobName = is_object($job) ? get_class($job) : (is_callable($job) ? 'Closure' : $job);

            $store()->addEvent('debug', 'queue', [
                'event' => 'JobQueued',
                'job' => $jobName,
                'connection' => $event->connectionName,
                'queue' => $event->queue,
                'trace' => $trace(),
            ]);
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event) use ($store, $trace): void {
            $contextStore = $store();
            $contextStore->initialize();
            SpxLifecycle::startIfEnabled();
            $jobId = method_exists($event->job, 'getJobId') ? $event->job->getJobId() : null;
            $contextStore->addContexts([
                'job_id' => $jobId,
                'job' => $event->job->getName(),
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'timestamp' => now()->toISOString(),
            ]);
            $contextStore->addEvent('debug', 'queue', [
                'event' => 'JobProcessing',
                'job' => $event->job->getName(),
                'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(),
                'connection' => $event->connectionName,
                'trace' => $trace(),
            ]);
        });

        Event::listen(JobProcessed::class, function (JobProcessed $event) use ($store, $trace): void {
            $contextStore = $store();
            $contextStore->addEvent('debug', 'queue', [
                'event' => 'JobProcessed',
                'job' => $event->job->getName(),
                'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(),
                'connection' => $event->connectionName,
                'trace' => $trace(),
            ]);
            ContextLogEmitter::emit($contextStore, null, 'Job completed');
            $contextStore->clear();
        });

        Event::listen(JobFailed::class, function (JobFailed $event) use ($store, $trace): void {
            $contextStore = $store();
            $contextStore->addEvent('error', 'queue', [
                'event' => 'JobFailed',
                'job' => $event->job->getName(),
                'queue' => $event->job->getQueue(),
                'attempts' => $event->job->attempts(),
                'connection' => $event->connectionName,
                'exception' => $event->exception->getMessage(),
                'trace' => $trace(),
            ]);
            ContextLogEmitter::emit($contextStore, null, 'Job failed');
            $contextStore->clear();
        });

        Event::listen(QueueBusy::class, function (QueueBusy $event) use ($store): void {
            $store()->addEvent('warning', 'queue', [
                'event' => 'QueueBusy',
                'connection' => $event->connectionName ?? $event->connection ?? null,
                'queue' => $event->queue,
                'size' => $event->size,
            ]);
        });
    }

    protected function bootMailLogging(): void
    {
        if (!config('context-logging.log.mail', false)) {
            return;
        }

        $messageSending = \Illuminate\Mail\Events\MessageSending::class;
        $messageSent = \Illuminate\Mail\Events\MessageSent::class;
        if (!class_exists($messageSending) || !class_exists($messageSent)) {
            return;
        }

        $store = fn () => $this->app->make(ContextStore::class);
        $trace = fn () => TraceHelper::getCollapsedTrace();

        Event::listen($messageSending, function ($event) use ($store, $trace): void {
            $message = $event->message ?? $event->data['message'] ?? null;
            $store()->addEvent('debug', 'mail', [
                'event' => 'MessageSending',
                'to' => $message ? $this->mailRecipients($message) : null,
                'subject' => $message?->getSubject(),
                'trace' => $trace(),
            ]);
        });

        Event::listen($messageSent, function ($event) use ($store, $trace): void {
            $message = $event->message ?? $event->data['message'] ?? null;
            $store()->addEvent('debug', 'mail', [
                'event' => 'MessageSent',
                'to' => $message ? $this->mailRecipients($message) : null,
                'subject' => $message?->getSubject(),
                'trace' => $trace(),
            ]);
        });
    }

    /**
     * @param \Symfony\Component\Mime\Email $message
     * @return array<string>|null
     */
    private function mailRecipients($message): ?array
    {
        if (!method_exists($message, 'getTo')) {
            return null;
        }
        $to = $message->getTo();
        $addresses = [];
        foreach ($to as $addr) {
            $addresses[] = $addr->getAddress();
        }
        return $addresses ?: null;
    }

    protected function bootReverbLogging(): void
    {
        if (!config('context-logging.log.reverb', false)) {
            return;
        }

        $store = fn () => $this->app->make(ContextStore::class);
        $trace = fn () => TraceHelper::getCollapsedTrace();

        $connectionId = function ($event): ?string {
            if (isset($event->connectionId)) {
                return $event->connectionId;
            }
            if (isset($event->connection) && is_object($event->connection) && method_exists($event->connection, 'id')) {
                return $event->connection->id();
            }
            return null;
        };

        // Reverb runs in a long-lived process (reverb:start, skipped from console context). Emit after each
        // event so Reverb activity is written to the log.
        $emitReverbEvent = function (array $eventPayload) use ($store): void {
            $contextStore = $store();
            $contextStore->initialize();
            $contextStore->addContexts(['source' => 'reverb', 'timestamp' => now()->toISOString()]);
            $contextStore->addEvent('debug', 'reverb', $eventPayload);
            ContextLogEmitter::emit($contextStore, null, 'Reverb event');
        };

        // Laravel Reverb 1.x does not dispatch ClientConnected/PusherSubscribe; it dispatches MessageSent,
        // MessageReceived, ChannelCreated. Use those to log connection and subscription activity.
        $messageSent = 'Laravel\Reverb\Events\MessageSent';
        $messageReceived = 'Laravel\Reverb\Events\MessageReceived';
        $channelCreated = 'Laravel\Reverb\Events\ChannelCreated';

        if (class_exists($messageSent)) {
            Event::listen($messageSent, function ($event) use ($connectionId, $emitReverbEvent, $trace): void {
                if (str_contains($event->message, 'pusher:connection_established')) {
                    $connection = $event->connection ?? null;
                    $emitReverbEvent([
                        'event' => 'ClientConnected',
                        'connection_id' => is_object($connection) && method_exists($connection, 'id') ? $connection->id() : null,
                    ]);
                }
            });
        }

        if (class_exists($messageReceived)) {
            Event::listen($messageReceived, function ($event) use ($connectionId, $emitReverbEvent, $trace): void {
                $payload = json_decode($event->message, true);
                if (!is_array($payload)) {
                    return;
                }
                $pusherEvent = $payload['event'] ?? '';
                $channel = $payload['data']['channel'] ?? $payload['channel'] ?? null;
                $connection = $event->connection ?? null;
                $connectionIdVal = is_object($connection) && method_exists($connection, 'id') ? $connection->id() : null;

                if ($pusherEvent === 'pusher:subscribe') {
                    $emitReverbEvent([
                        'event' => 'PusherSubscribe',
                        'channel' => $channel,
                        'connection_id' => $connectionIdVal,
                    ]);
                    return;
                }
                if ($pusherEvent === 'pusher:unsubscribe') {
                    $emitReverbEvent([
                        'event' => 'PusherUnsubscribe',
                        'channel' => $channel,
                        'connection_id' => $connectionIdVal,
                    ]);
                }
            });
        }

        if (class_exists($channelCreated)) {
            Event::listen($channelCreated, function ($event) use ($emitReverbEvent, $trace): void {
                $channelName = isset($event->channel) && method_exists($event->channel, 'name')
                    ? $event->channel->name()
                    : null;
                $emitReverbEvent([
                    'event' => 'ChannelCreated',
                    'channel' => $channelName,
                    'trace' => $trace(),
                ]);
            });
        }

        // Optional: if a Reverb version dispatches these events (e.g. future or fork), log them too.
        $clientConnected = 'Laravel\Reverb\Events\ClientConnected';
        $clientDisconnected = 'Laravel\Reverb\Events\ClientDisconnected';
        $pusherSubscribe = 'Laravel\Reverb\Events\PusherSubscribe';
        $pusherUnsubscribe = 'Laravel\Reverb\Events\PusherUnsubscribe';

        if (class_exists($clientConnected)) {
            Event::listen($clientConnected, function ($event) use ($connectionId, $emitReverbEvent, $trace): void {
                $emitReverbEvent([
                    'event' => 'ClientConnected',
                    'connection_id' => $connectionId($event),
                    'trace' => $trace(),
                ]);
            });
        }

        if (class_exists($clientDisconnected)) {
            Event::listen($clientDisconnected, function ($event) use ($connectionId, $emitReverbEvent, $trace): void {
                $emitReverbEvent([
                    'event' => 'ClientDisconnected',
                    'connection_id' => $connectionId($event),
                    'trace' => $trace(),
                ]);
            });
        }

        if (class_exists($pusherSubscribe)) {
            Event::listen($pusherSubscribe, function ($event) use ($connectionId, $emitReverbEvent, $trace): void {
                $emitReverbEvent([
                    'event' => 'PusherSubscribe',
                    'channel' => $event->channel ?? null,
                    'connection_id' => $connectionId($event),
                    'trace' => $trace(),
                ]);
            });
        }

        if (class_exists($pusherUnsubscribe)) {
            Event::listen($pusherUnsubscribe, function ($event) use ($connectionId, $emitReverbEvent, $trace): void {
                $emitReverbEvent([
                    'event' => 'PusherUnsubscribe',
                    'channel' => $event->channel ?? null,
                    'connection_id' => $connectionId($event),
                    'trace' => $trace(),
                ]);
            });
        }
    }

    protected function bootScheduleLogging(): void
    {
        if (!config('context-logging.log.schedule', false)) {
            return;
        }

        $taskStarting = \Illuminate\Console\Events\ScheduledTaskStarting::class;
        $taskFinished = \Illuminate\Console\Events\ScheduledTaskFinished::class;
        $taskFailed = \Illuminate\Console\Events\ScheduledTaskFailed::class;
        $taskSkipped = \Illuminate\Console\Events\ScheduledTaskSkipped::class;

        if (!class_exists($taskStarting)) {
            return;
        }

        $store = fn () => $this->app->make(ContextStore::class);
        $trace = fn () => TraceHelper::getCollapsedTrace();

        Event::listen($taskStarting, function ($event) use ($store, $trace): void {
            $store()->addEvent('debug', 'schedule', [
                'event' => 'ScheduledTaskStarting',
                'task' => $event->task->getSummaryForDisplay(),
                'trace' => $trace(),
            ]);
        });

        if (class_exists($taskFinished)) {
            Event::listen($taskFinished, function ($event) use ($store, $trace): void {
                $store()->addEvent('debug', 'schedule', [
                    'event' => 'ScheduledTaskFinished',
                    'task' => $event->task->getSummaryForDisplay(),
                    'runtime' => $event->runtime ?? null,
                    'trace' => $trace(),
                ]);
            });
        }

        if (class_exists($taskFailed)) {
            Event::listen($taskFailed, function ($event) use ($store): void {
                $store()->addEvent('error', 'schedule', [
                    'event' => 'ScheduledTaskFailed',
                    'task' => $event->task->getSummaryForDisplay(),
                    'exception' => $event->exception->getMessage(),
                    'trace' => TraceHelper::getCollapsedTrace(),
                ]);
            });
        }

        if (class_exists($taskSkipped)) {
            Event::listen($taskSkipped, function ($event) use ($store, $trace): void {
                $store()->addEvent('debug', 'schedule', [
                    'event' => 'ScheduledTaskSkipped',
                    'task' => $event->task->getSummaryForDisplay(),
                    'trace' => $trace(),
                ]);
            });
        }
    }

    protected function bootNotificationsLogging(): void
    {
        if (!config('context-logging.log.notifications', false)) {
            return;
        }

        $notificationSending = \Illuminate\Notifications\Events\NotificationSending::class;
        $notificationSent = \Illuminate\Notifications\Events\NotificationSent::class;
        $notificationFailed = \Illuminate\Notifications\Events\NotificationFailed::class;

        if (!class_exists($notificationSending) || !class_exists($notificationSent)) {
            return;
        }

        $store = fn () => $this->app->make(ContextStore::class);
        $trace = fn () => TraceHelper::getCollapsedTrace();
        $describeNotifiable = function ($notifiable): ?string {
            if ($notifiable === null) {
                return null;
            }
            if (is_object($notifiable) && method_exists($notifiable, 'getKey')) {
                return get_class($notifiable) . '#' . $notifiable->getKey();
            }
            return is_object($notifiable) ? get_class($notifiable) : (string) $notifiable;
        };
        // When the Telegram bridge is on, it owns telegram-channel events (message + drop).
        // Skip the generic notifications logger for that channel to avoid duplicate timeline rows.
        $skipTelegram = fn ($event): bool => (bool) config('context-logging.telegram.enabled', false)
            && is_string($event->channel ?? null)
            && strtolower((string) $event->channel) === 'telegram';

        Event::listen($notificationSending, function ($event) use ($store, $trace, $describeNotifiable, $skipTelegram): void {
            if ($skipTelegram($event)) {
                return;
            }
            $store()->addEvent('debug', 'notifications', [
                'event' => 'NotificationSending',
                'channel' => $event->channel ?? null,
                'notification' => is_object($event->notification) ? get_class($event->notification) : null,
                'notifiable' => $describeNotifiable($event->notifiable ?? null),
                'trace' => $trace(),
            ]);
        });

        Event::listen($notificationSent, function ($event) use ($store, $trace, $describeNotifiable, $skipTelegram): void {
            if ($skipTelegram($event)) {
                return;
            }
            $store()->addEvent('debug', 'notifications', [
                'event' => 'NotificationSent',
                'channel' => $event->channel ?? null,
                'notification' => is_object($event->notification) ? get_class($event->notification) : null,
                'notifiable' => $describeNotifiable($event->notifiable ?? null),
                'trace' => $trace(),
            ]);
        });

        if (class_exists($notificationFailed)) {
            Event::listen($notificationFailed, function ($event) use ($store, $describeNotifiable, $skipTelegram): void {
                if ($skipTelegram($event)) {
                    return;
                }
                $exception = null;

                if (is_array($event->data ?? null) && array_key_exists('exception', $event->data)) {
                    $failedException = $event->data['exception'];
                    $exception = $failedException instanceof \Throwable
                        ? $failedException->getMessage()
                        : (is_scalar($failedException) ? (string) $failedException : null);
                }

                $store()->addEvent('error', 'notifications', [
                    'event' => 'NotificationFailed',
                    'channel' => $event->channel ?? null,
                    'notification' => is_object($event->notification) ? get_class($event->notification) : null,
                    'notifiable' => $describeNotifiable($event->notifiable ?? null),
                    'exception' => $exception,
                    'trace' => TraceHelper::getCollapsedTrace(),
                ]);
            });
        }
    }

    protected function bootBroadcastingLogging(): void
    {
        if (!config('context-logging.log.broadcasting', false)) {
            return;
        }

        if (!class_exists(\Illuminate\Broadcasting\BroadcastEvent::class)) {
            return;
        }

        $store = fn () => $this->app->make(ContextStore::class);
        $trace = fn () => TraceHelper::getCollapsedTrace();

        $logBroadcastJob = function (string $eventName, $event) use ($store, $trace): void {
            $queueJob = $event->job ?? null;
            if ($queueJob === null) {
                return;
            }
            // JobProcessing/JobProcessed pass the queue driver's Job (e.g. RedisJob). For object jobs the
            // payload['job'] is always CallQueuedHandler@call; the real command is in payload['data']['commandName'].
            $commandClass = method_exists($queueJob, 'resolveQueuedJobClass') ? $queueJob->resolveQueuedJobClass() : null;
            if ($commandClass !== \Illuminate\Broadcasting\BroadcastEvent::class) {
                return;
            }
            $eventClass = method_exists($queueJob, 'resolveName') ? $queueJob->resolveName() : $commandClass;
            $channels = null;
            // After the job runs, the queue Job's resolved command is in protected $instance (BroadcastEvent).
            $broadcastEvent = null;
            if (property_exists($queueJob, 'instance')) {
                try {
                    $ref = new \ReflectionProperty($queueJob, 'instance');
                    $ref->setAccessible(true);
                    $broadcastEvent = $ref->getValue($queueJob);
                } catch (\Throwable) {
                    // ignore
                }
            }
            $innerEvent = ($broadcastEvent instanceof \Illuminate\Broadcasting\BroadcastEvent && isset($broadcastEvent->event))
                ? $broadcastEvent->event
                : null;
            if (is_object($innerEvent) && method_exists($innerEvent, 'broadcastOn')) {
                $channels = [];
                foreach ($innerEvent->broadcastOn() as $channel) {
                    $channels[] = $channel instanceof \Illuminate\Broadcasting\Channel
                        ? ($channel->name ?? (string) $channel)
                        : (string) $channel;
                }
            }
            $store()->addEvent('debug', 'broadcasting', array_filter([
                'event' => $eventName,
                'broadcast_event' => $eventClass,
                'channels' => $channels,
                'trace' => $trace(),
            ]));
        };

        Event::listen(JobQueued::class, function (JobQueued $event) use ($store, $trace): void {
            $job = $event->job;
            if (!$job instanceof \Illuminate\Broadcasting\BroadcastEvent) {
                return;
            }
            $eventClass = isset($job->event) && is_object($job->event) ? $job->event::class : $job::class;
            $channels = null;
            $innerEvent = $job->event ?? null;
            if (is_object($innerEvent) && method_exists($innerEvent, 'broadcastOn')) {
                $channels = [];
                foreach ($innerEvent->broadcastOn() as $channel) {
                    $channels[] = $channel instanceof \Illuminate\Broadcasting\Channel
                        ? ($channel->name ?? (string) $channel)
                        : (string) $channel;
                }
            }
            $store()->addEvent('debug', 'broadcasting', array_filter([
                'event' => 'BroadcastQueued',
                'broadcast_event' => $eventClass,
                'channels' => $channels,
                'connection' => $event->connectionName,
                'queue' => $event->queue,
                'trace' => $trace(),
            ]));
        });

        Event::listen(\Illuminate\Queue\Events\JobProcessing::class, function ($event) use ($logBroadcastJob): void {
            $logBroadcastJob('BroadcastProcessing', $event);
        });

        Event::listen(\Illuminate\Queue\Events\JobProcessed::class, function ($event) use ($logBroadcastJob): void {
            $logBroadcastJob('BroadcastProcessed', $event);
        });
    }

    /**
     * Bootstrap context logging for console (artisan commands).
     * Initializes context without request info and emits on command finish.
     * Commands in config context-logging.console.skip_commands are not wrapped
     * (e.g. queue:work so each job logs separately).
     */
    protected function bootConsoleContext(): void
    {
        if (!config('context-logging.log.console', false)) {
            return;
        }

        $this->app->booted(function () {
            $contextStore = $this->app->make(ContextStore::class);
            $skipEmit = [false];

            $this->app['events']->listen(CommandStarting::class, function (CommandStarting $event) use ($contextStore, &$skipEmit) {
                $command = $event->command ?? 'unknown';
                if ($this->shouldSkipConsoleCommand($command)) {
                    $skipEmit[0] = true;
                    $contextStore->clear();
                    return;
                }
                $skipEmit[0] = false;
                $contextStore->initialize();
                $contextStore->addContexts([
                    'run_id' => (string) Str::uuid(),
                    'timestamp' => now()->toISOString(),
                    'command' => $command,
                    'args' => $event->input->getArguments(),
                ]);
            });

            $this->app->terminating(function () use ($contextStore, &$skipEmit) {
                if ($skipEmit[0]) {
                    return;
                }
                ContextLogEmitter::emit($contextStore, null, 'Console run completed');
                $contextStore->clear();
            });
        });
    }

    protected function registerShutdownFallback(): void
    {
        register_shutdown_function(function (): void {
            if (!isset($this->app) || !$this->app->bound(ContextStore::class)) {
                return;
            }

            $contextStore = $this->app->make(ContextStore::class);

            if (
                !$contextStore->hasLifecycleStarted()
                || $contextStore->isEmissionSuppressed()
                || $contextStore->hasBeenEmitted()
            ) {
                return;
            }

            ContextLogEmitter::emitInterruptedLifecycle($contextStore, error_get_last());
            $contextStore->clear();
        });
    }

    protected function overrideTinkerCommand(): void
    {
        if (!class_exists(TinkerCommand::class) || !$this->app->bound('command.tinker')) {
            return;
        }

        $this->app->extend('command.tinker', function () {
            return $this->app->make(Console\ContextLoggingTinkerCommand::class);
        });
    }

    /**
     * Whether the given artisan command should skip console context wrapping.
     */
    protected function shouldSkipConsoleCommand(string $command): bool
    {
        if ($command === 'tinker') {
            return true;
        }

        $patterns = config('context-logging.console.skip_commands', []);
        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern !== '' && Str::is($pattern, $command)) {
                return true;
            }
        }
        return false;
    }
}