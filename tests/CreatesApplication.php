<?php declare(strict_types = 1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        if ($app instanceof Application) {
            $kernel = $app->make(Kernel::class);

            if ($kernel instanceof \LaravelZero\Framework\Kernel) {
                $kernel->bootstrap();
            } else {
                throw new \Exception("failed to bootstrap kernel");
            }

            return $app;
        }

        throw new \Exception("failed to create application");
    }
}
