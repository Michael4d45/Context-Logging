<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Michael4d45\ContextLogging\ContextStore;
use Michael4d45\ContextLogging\Database\Connections\CapturingMariaDbConnection;
use Michael4d45\ContextLogging\Database\Connections\CapturingMySqlConnection;
use Michael4d45\ContextLogging\Database\Connections\CapturingPostgresConnection;
use Michael4d45\ContextLogging\Database\Connections\CapturingSQLiteConnection;
use Michael4d45\ContextLogging\Database\Connections\CapturingSqlServerConnection;
use Michael4d45\ContextLogging\Support\TraceHelper;
use Throwable;

/**
 * SQL event logging with optional result capture (Guzzle-style connection wrap).
 *
 * When log.db is off: no listeners, no resolvers.
 * When log.db is on and capture_results is off: DB::listen only (zero Connection wrapping).
 * When both are on: Connection::resolverFor installs capturing driver subclasses.
 */
class DatabaseInstrumentation
{
    protected bool $listenerRegistered = false;

    protected bool $resolversRegistered = false;

    /**
     * @var array<string, (\Closure|null)>
     */
    protected static array $previousResolvers = [];

    /**
     * @var array<string, class-string<Connection>>
     */
    protected array $capturingClasses = [
        'mysql' => CapturingMySqlConnection::class,
        'mariadb' => CapturingMariaDbConnection::class,
        'pgsql' => CapturingPostgresConnection::class,
        'sqlite' => CapturingSQLiteConnection::class,
        'sqlsrv' => CapturingSqlServerConnection::class,
    ];

    public function __construct(
        protected ContextStore $contextStore,
    ) {}

    /**
     * Register SQL logging (and optional result capture) once.
     *
     * @param  bool  $resolversOnly  When true, only install Connection resolvers
     *                               (safe to call from ServiceProvider::register()).
     */
    public function register(bool $resolversOnly = false): void
    {
        if (! (bool) config('context-logging.log.db', false)) {
            return;
        }

        if ((bool) config('context-logging.database.capture_results', false)) {
            $this->registerResolvers();
        }

        if ($resolversOnly) {
            return;
        }

        $this->registerListener();
    }

    /**
     * Whether result capture wrapping is active.
     */
    public function captureResultsEnabled(): bool
    {
        return (bool) config('context-logging.log.db', false)
            && (bool) config('context-logging.database.capture_results', false);
    }

    protected function registerListener(): void
    {
        if ($this->listenerRegistered) {
            return;
        }

        $this->listenerRegistered = true;
        $contextStore = $this->contextStore;

        DB::listen(function (QueryExecuted $query) use ($contextStore): void {
            $context = [
                'SQL' => $query->toRawSql().';',
                'execution_time' => $query->time.'ms',
                'trace' => TraceHelper::getCollapsedTrace(),
            ];

            if ($this->captureResultsEnabled()) {
                try {
                    $connection = $query->connection;
                    if (method_exists($connection, 'pullCapturedResult')) {
                        $captured = $connection->pullCapturedResult();
                        if (is_array($captured) && $captured !== []) {
                            $context['result'] = $captured;
                        }
                    }
                } catch (Throwable) {
                    // Never break query logging if capture fails.
                }
            }

            $contextStore->addEvent('debug', 'sql', $context);
        });
    }

    /**
     * Install capturing Connection subclasses via Laravel's driver resolvers.
     */
    protected function registerResolvers(): void
    {
        if ($this->resolversRegistered) {
            return;
        }

        $this->resolversRegistered = true;

        foreach ($this->capturingClasses as $driver => $capturingClass) {
            if (! array_key_exists($driver, self::$previousResolvers)) {
                self::$previousResolvers[$driver] = Connection::getResolver($driver);
            }

            $previous = self::$previousResolvers[$driver];

            Connection::resolverFor(
                $driver,
                function ($connection, $database, $prefix, $config) use ($previous, $capturingClass) {
                    if ($previous !== null) {
                        $resolved = $previous($connection, $database, $prefix, $config);

                        if (method_exists($resolved, 'pullCapturedResult')) {
                            return $resolved;
                        }
                    }

                    return new $capturingClass($connection, $database, $prefix, $config);
                }
            );
        }

        $this->purgeExistingConnections();
    }

    /**
     * Drop any connections opened before our resolvers were installed so the
     * next resolve uses capturing subclasses.
     */
    protected function purgeExistingConnections(): void
    {
        try {
            foreach (array_keys((array) config('database.connections', [])) as $name) {
                DB::purge($name);
            }
        } catch (Throwable) {
            // Ignore — next resolve will still use the new resolvers.
        }
    }

    /**
     * Restore any Connection resolvers replaced by registerResolvers().
     * Intended for tests; production request lifecycle does not need this.
     */
    public static function restoreResolvers(): void
    {
        $ref = new \ReflectionClass(Connection::class);
        $property = $ref->getProperty('resolvers');
        $resolvers = $property->getValue() ?? [];
        if (! is_array($resolvers)) {
            $resolvers = [];
        }

        foreach (self::$previousResolvers as $driver => $previous) {
            if ($previous === null) {
                unset($resolvers[$driver]);
            } else {
                $resolvers[$driver] = $previous;
            }
        }

        $property->setValue(null, $resolvers);
        self::$previousResolvers = [];
    }
}
