<?php

// Suppress deprecation notices from Laravel Zero's database config on PHP 8.5+
// (picoHP doesn't use a database; this will go away when we remove Laravel Zero)
error_reporting(E_ALL & ~E_DEPRECATED);

use LaravelZero\Framework\Application;
use Illuminate\Foundation\Configuration\Exceptions;

return Application::configure(basePath: dirname(__DIR__))
    ->withExceptions(function (Exceptions $exceptions) {
        // get a full stack trace for all exceptions
        $exceptions->report(function (\Throwable $e) {
            echo $e->getTraceAsString() . PHP_EOL;
        });
    })->create();
