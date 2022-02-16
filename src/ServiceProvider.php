<?php

namespace ThibaudDauce\PHPStanBlade;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function boot(): void
    {
        $this->commands([TouchCacheCommand::class]);
    }
}