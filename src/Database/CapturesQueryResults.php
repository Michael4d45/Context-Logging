<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Database;

use Closure;
use Throwable;

/**
 * Connection trait that snapshots query results for the DB listener.
 *
 * Stashing happens inside the run() callback (before logQuery / QueryExecuted),
 * so the listener can pull a truncated/redacted copy without re-querying.
 * The application always receives the original unmodified result.
 */
trait CapturesQueryResults
{
    /**
     * @var array<string, mixed>|null
     */
    protected ?array $contextLoggingCapturedResult = null;

    /**
     * {@inheritdoc}
     */
    protected function run($query, $bindings, Closure $callback)
    {
        return parent::run($query, $bindings, function ($query, $bindings) use ($callback) {
            $result = $callback($query, $bindings);

            try {
                $this->contextLoggingCapturedResult = ResultCapturer::capture($result);
            } catch (Throwable) {
                $this->contextLoggingCapturedResult = null;
            }

            return $result;
        });
    }

    /**
     * Pull and clear the most recently captured result snapshot.
     *
     * @return array<string, mixed>|null
     */
    public function pullCapturedResult(): ?array
    {
        $captured = $this->contextLoggingCapturedResult;
        $this->contextLoggingCapturedResult = null;

        return $captured;
    }
}
