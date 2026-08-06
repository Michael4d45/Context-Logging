<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Database\Connections;

use Illuminate\Database\SqlServerConnection;
use Michael4d45\ContextLogging\Database\CapturesQueryResults;

class CapturingSqlServerConnection extends SqlServerConnection
{
    use CapturesQueryResults;
}
