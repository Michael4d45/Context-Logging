<?php

declare(strict_types=1);

namespace Michael4d45\ContextLogging\Database\Connections;

use Illuminate\Database\SQLiteConnection;
use Michael4d45\ContextLogging\Database\CapturesQueryResults;

class CapturingSQLiteConnection extends SQLiteConnection
{
    use CapturesQueryResults;
}
