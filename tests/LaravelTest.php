<?php

namespace Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use ThibaudDauce\PHPStanBlade\CacheManager;

class LaravelTest extends TestCase
{
    /** @test */
    public function it_works()
    {
        // Clearing the cache before the first run.
        (new Process(['php', 'vendor/bin/phpstan', 'clear-result-cache']))
            ->setWorkingDirectory(__DIR__ . '/laravel')
            ->run();

        $expected_output = file_get_contents(__DIR__ . '/output.txt');

        [$output, $duration] = $this->run_phpstan();
        $this->assertEquals($expected_output, $output);

        // Running the analyse a second time show the same errors.
        [$output, $duration] = $this->run_phpstan();
        $this->assertEquals($expected_output, $output);
        $this->assertTrue($duration < 1, "The 2nd analyse was longer than 1s ({$duration}s).");

        touch(__DIR__ . '/laravel/resources/views/addition.blade.php');
        [$output, $duration] = $this->run_phpstan();
        $this->assertEquals($expected_output, $output);
        $this->assertTrue($duration > 1, "The 3rd analyse was faster than 1s ({$duration}s).");
    }

    /**
     * @return array{0: string, 1: float}
     */
    private function run_phpstan(): array
    {
        (new CacheManager)->touch_cache(fn(string $info) => $info, fn(string $error) => throw new Exception($error));

        $start = microtime(true);
        $process = (new Process(['php', 'vendor/bin/phpstan', 'analyse', '--error-format', 'blade']))
            ->setWorkingDirectory(__DIR__ . '/laravel');

        $process->run();
        $duration = round(microtime(true) - $start, 3);

        return [$process->getOutput(), $duration];
    }
}