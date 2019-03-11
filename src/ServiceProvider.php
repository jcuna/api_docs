<?php

declare(strict_types=1);

namespace Jcuna\ApiDocs;

use Jcuna\ApiDocs\Cli\ApiDocs;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    /**
     * Register package's services.
     */
    public function boot()
    {
        app()->configure('jcuna/swagger');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ApiDocs::class
            ]);
        }
    }
}
