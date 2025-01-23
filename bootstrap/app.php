<?php

use LaravelZero\Framework\Application;
use Illuminate\Foundation\Configuration\Exceptions;

return Application::configure(basePath: dirname(__DIR__))
    ->withExceptions(function (Exceptions $exceptions) {
        // get a full stack trace for all exceptions
        $exceptions->report(function (\Throwable $e) {
            echo $e->getTraceAsString() . PHP_EOL;
        });
    })->create();
