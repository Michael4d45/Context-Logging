<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Database\Connections;

use Illuminate\Database\MariaDbConnection;
use Michael4d45\ContextLogging\Database\CapturesQueryResults;

class CapturingMariaDbConnection extends MariaDbConnection
{
    use CapturesQueryResults;
}
