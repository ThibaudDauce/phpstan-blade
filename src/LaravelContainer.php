<?php

namespace ThibaudDauce\PHPStanBlade;

use PHPStan\File\FileHelper;
use Illuminate\Support\Facades\Blade;
use Illuminate\Foundation\Application;
use Illuminate\Contracts\Console\Kernel;

class LaravelContainer
{
    private Application $container;

    public function __construct(
        FileHelper $fileHelper,
    ) {
        // TODO add parameter in config to change this path
        $bootstrapPath = $fileHelper->absolutizePath('./bootstrap/app.php');

        $this->container = require $bootstrapPath;
        $this->container->make(Kernel::class)->bootstrap();

        Blade::directive('entangle', function ($expression) {
            return '';
        });
    }

    /**
     * @template T
     * @param class-string<T> $class
     * @return T
     */
    public function make(string $class)
    {
        return $this->container->make($class); // @phpstan-ignore-line
    }
}
