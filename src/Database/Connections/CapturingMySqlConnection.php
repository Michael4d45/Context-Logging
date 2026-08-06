<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Database\Connections;

use Illuminate\Database\MySqlConnection;
use Michael4d45\ContextLogging\Database\CapturesQueryResults;

class CapturingMySqlConnection extends MySqlConnection
{
    use CapturesQueryResults;
}
