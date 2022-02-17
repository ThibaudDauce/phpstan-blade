<?php

namespace ThibaudDauce\PHPStanBlade;

use Closure;
use Illuminate\Console\Command;

/**
 * PHPStan do not track the view files in its cache. These files are inside a
 * temp folder because it's the result of the Blade compilation (they do not really exists)
 * 
 * If we change the content of a view, we want to force PHPStan to do a new analyze of the controller.
 * The analyse of the controller will do a new analyse of the views.
 * 
 * There is no PHP way right now to tell PHPStan to track the Blade files (https://github.com/phpstan/phpstan/discussions/6602)
 * 
 * So the goal of this command is to check (via a custom metadata file we store during analyze) the last modified time of the views
 * and if a view was changed, change the hash of the PHP files referencing this view. To change the hash of the PHP file we fetch the
 * content, apply the hash function based of the PHPStan code and replace this hash by a fake one inside PHPStan cache file. So when
 * PHPStan is run again, it'll think that the PHP file was changed and re-run the analyse.
 * 
 * This command must be called before running `./vendor/bin/phpstan analyse`. You can do a Composer script to batch the commands together.
 */
class TouchCacheCommand extends Command
{
    protected $signature = 'phpstan-blade:touch-cache';
    protected $description = 'Force the update of cache when views changed.';

    public function handle(): void
    {
       (new CacheManager)->touch_cache(Closure::fromCallable([$this, 'info']), Closure::fromCallable([$this, 'error']));
    }
}