<?php

namespace ThibaudDauce\PHPStanBlade;

use Exception;

class CacheManager
{
    const FAKE_SHA1 = 'XXX';

    public function path(): string
    {
        $cache_file_path = sys_get_temp_dir() . '/phpstan-blade/cache.txt';
        if (! is_dir(sys_get_temp_dir() . '/phpstan-blade/')) {
            mkdir(sys_get_temp_dir() . '/phpstan-blade/');
        }

        return $cache_file_path;
    }

    public function add_dependency_to_view_file(string $php_file, string $view_file): void
    {
        $last_modified = filemtime($view_file);
        if (! $last_modified) throw new Exception("Cannot find last modified date of {$view_file}.");

        file_put_contents($this->path(), json_encode([
            'php_file' => $php_file,
            'view_file' => $view_file,
            'mtime' => $last_modified,
        ]) . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @return array<string, array<string, int>> */
    public function get_dependencies(): array
    {
        if (! file_exists($this->path())) return [];

        $content = file_get_contents($this->path());
        if (! $content) return [];

        $lines = array_filter(explode(PHP_EOL, $content));
        $dependencies = [];
        foreach ($lines as $line) {
            $dependency = json_decode($line);

            $dependencies[$dependency->php_file] ??= []; // @phpstan-ignore-line
            $dependencies[$dependency->php_file][$dependency->view_file] = $dependency->mtime; // @phpstan-ignore-line
        }

        return $dependencies; // @phpstan-ignore-line
    }

    /**
     * @param callable(string): void $info
     * @param callable(string): void $error
     */
    public function touch_cache($info, $error): void
    {
        $dependencies = $this->get_dependencies();

        $cache_path = '/tmp/phpstan/resultCache.php';
        if (! file_exists($cache_path)) {
            $info("No PHPStan cache at {$cache_path}, skipping…");
            return;
        }

        $content = file_get_contents($cache_path);
        if (! $content) {
            $error("Impossible to read the PHPStan cache at {$cache_path}.");
            return;
        }

        foreach ($dependencies as $php_file => $views) {
            if (! file_exists($php_file)) continue;

            foreach ($views as $view_file => $mtime) {
                if (! file_exists($view_file) || filemtime($view_file) > $mtime) {
                    $content = file_get_contents($php_file);
                    if (! $content) continue;

                    /**
                     * These line are copied from https://github.com/phpstan/phpstan-src/blob/86a63ff1f07352fffe84b2ad0468d5d14a0fc2d3/src/Analyser/ResultCache/ResultCacheManager.php#L714-L716
                     * They must stay the same, if they differ, touching the cache will do nothing.
                     */
                    $contents = str_replace("\r\n", "\n", $content);
                    $hash = sha1($contents);

                    $content = str_replace($hash, self::FAKE_SHA1, $content);
                    $info("{$view_file} changed so change the hash of {$php_file} to force new analyse.");

                    // We break here because the PHP file was touch so even if multiple view files changed, it's not going to do more.
                    break;
                }
            }
        }

        file_put_contents($cache_path, $content);
    }
}
