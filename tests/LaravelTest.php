<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class LaravelTest extends TestCase
{
    /** @test */
    public function it_works()
    {
        (new Process(['php', 'vendor/bin/phpstan', 'clear-result-cache']))
            ->setWorkingDirectory(__DIR__ . '/laravel_phpstan')
            ->run();

        $process = (new Process(['php', 'vendor/bin/phpstan', 'analyse', '--error-format', 'blade']))
            ->setWorkingDirectory(__DIR__ . '/laravel_phpstan');

        $process->run();

        $output = $process->getOutput();

        // file_put_contents(__DIR__ . '/output.txt', $output);

        $this->assertEquals(file_get_contents(__DIR__ . '/output.txt'), $output);
    }
}