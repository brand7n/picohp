<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ensure we throw exceptions in case of assertion failure
        echo "zend.assertions: ";
        var_dump(ini_get('zend.assertions'));
        ini_set('assert.exception', '1');
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }
}
