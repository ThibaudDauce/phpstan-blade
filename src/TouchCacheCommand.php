<?php

namespace ThibaudDauce\PHPStanBlade;

use Illuminate\Console\Command;

class TouchCacheCommand extends Command
{
    protected $signature = 'phpstan-blade:touch-cache';
    protected $description = 'Force the update of cache when views changed.';

    public function handle(): void
    {
        $dependencies = (new CacheManager)->get_dependencies();

        $cache_path = '/tmp/phpstan/resultCache.php';
        if (! file_exists($cache_path)) return;

        $content = file_get_contents($cache_path);
        if (! $content) return;

        foreach ($dependencies as $php_file => $templates) {
            if (! file_exists($php_file)) continue;

            foreach ($templates as $template_file => $mtime) {
                if (! file_exists($template_file) || filemtime($template_file) > $mtime) {
                    $content = file_get_contents($php_file);
                    if (! $content) continue;

                    // Les deux lignes suivantes sont copiées de PHPStan https://github.com/phpstan/phpstan-src/blob/86a63ff1f07352fffe84b2ad0468d5d14a0fc2d3/src/Analyser/ResultCache/ResultCacheManager.php#L714-L716
                    $contents = str_replace("\r\n", "\n", $content);
                    $hash = sha1($contents);

                    $content = str_replace($hash, 'coucou', $content);
                    break;
                }
            }
        }

        file_put_contents($cache_path, $content);
    }
}