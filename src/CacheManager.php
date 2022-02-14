<?php

namespace ThibaudDauce\PHPStanBlade;

use Exception;

class CacheManager
{
    public function path(): string
    {
        $cache_file_path = sys_get_temp_dir() . '/phpstan-blade/cache.txt';
        if (! is_dir(sys_get_temp_dir() . '/phpstan-blade/')) {
            mkdir(sys_get_temp_dir() . '/phpstan-blade/');
        }

        return $cache_file_path;
    }

    public function add_dependency_to_template_file(string $php_file, string $template_file): void
    {
        $dependencies = $this->get_dependencies();

        $last_modified = filemtime($template_file);
        if (! $last_modified) throw new Exception("Cannot find last modified date of {$template_file}.");

        $dependencies[$php_file] ??= [];
        $dependencies[$php_file][$template_file] = $last_modified;

        $this->save($dependencies);
    }

    /** @return array<string, array<string, int>> */
    public function get_dependencies(): array
    {
        if (! file_exists($this->path())) return [];

        $content = file_get_contents($this->path());
        if (! $content) return [];

        $lines = explode(PHP_EOL, $content);
        $dependencies = [];
        foreach ($lines as $line) {
            $dependency = json_decode($line);

            $dependencies[$dependency->php_file] ??= []; // @phpstan-ignore-line
            $dependencies[$dependency->php_file][$dependency->template_file] = $dependency->mtime; // @phpstan-ignore-line
        }

        return $dependencies; // @phpstan-ignore-line
    }

    /** @param array<string, array<string, int>> $dependencies */
    public function save(array $dependencies): void
    {
        $lines = [];
        foreach ($dependencies as $php_file => $templates) {
            foreach ($templates as $template_file => $mtime) {
                $lines[] = json_encode([
                    'php_file' => $php_file,
                    'template_file' => $template_file,
                    'mtime' => $mtime,
                ]);
            }
        }

        file_put_contents($this->path(), implode(PHP_EOL, $lines));
    }
}
