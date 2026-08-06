<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Database\Connections;

use Illuminate\Database\PostgresConnection;
use Michael4d45\ContextLogging\Database\CapturesQueryResults;

class CapturingPostgresConnection extends PostgresConnection
{
    use CapturesQueryResults;
}
