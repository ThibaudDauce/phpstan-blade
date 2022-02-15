<?php

declare(strict_types=1);

namespace ThibaudDauce\PHPStanBlade;

use PHPStan\Analyser\Error;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\Output;
use PHPStan\File\RelativePathHelper;

/**
 * @see https://github.com/phpstan/phpstan-src/blob/master/src/Command/ErrorFormatter/TableErrorFormatter.php
 */
class BladeFormatter
{
    public function __construct(
        private RelativePathHelper $relativePathHelper,
    ) {
    }

    public function formatErrors(
        AnalysisResult $analysisResult,
        Output $output,
    ): int {
        $style = $output->getStyle();

        if (! $analysisResult->hasErrors() && ! $analysisResult->hasWarnings()) {
            $style->success('No errors');
            return 0;
        }

        /** @var array<string, Error[]> $fileErrors */
        $fileErrors = [];
        foreach ($analysisResult->getFileSpecificErrors() as $fileSpecificError) {
            /** @var string */
            $view_name = $fileSpecificError->getMetadata()['view_name'] ?? null;

            /** @var string */
            $controller_line = $fileSpecificError->getMetadata()['controller_line'] ?? null;

            /** @var string */
            $controller_path = $fileSpecificError->getMetadata()['controller_path'] ?? null;

            /** @var array<array{0: string, 1: int}> */
            $includes_stacktrace = $fileSpecificError->getMetadata()['includes_stacktrace'] ?? null;

            $error_path = $this->relativePathHelper->getRelativePath($fileSpecificError->getFile());
            
            if ($view_name && $controller_line && $controller_path) {
                $controller_relative_path = $this->relativePathHelper->getRelativePath($controller_path);

                $key = $view_name;

                foreach (array_reverse($includes_stacktrace) as $stacktrace) {
                    $key .= " ← <fg=gray>{$stacktrace[0]}:{$stacktrace[1]}</>";
                }

                $key .= " ← <fg=gray>{$controller_relative_path}:{$controller_line}</>";
            } else {
                $key = $error_path;
            }

            if (! isset($fileErrors[$key])) {
                $fileErrors[$key] = [];
            }

            $fileErrors[$key][] = $fileSpecificError;
        }

        foreach ($fileErrors as $file => $errors) {
            $rows = [];
            foreach ($errors as $error) {
                $message = $error->getMessage();

                $rows[] = [
                    (string) $error->getLine(),
                    $message,
                ];
            }

            $style->table(['Line', $file], $rows);
        }

        if (count($analysisResult->getNotFileSpecificErrors()) > 0) {
            $style->table(['', 'Error'], array_map(static fn (string $error): array => ['', $error], $analysisResult->getNotFileSpecificErrors()));
        }

        $warningsCount = count($analysisResult->getWarnings());
        if ($warningsCount > 0) {
            $style->table(['', 'Warning'], array_map(static fn (string $warning): array => ['', $warning], $analysisResult->getWarnings()));
        }

        $finalMessage = sprintf($analysisResult->getTotalErrorsCount() === 1 ? 'Found %d error' : 'Found %d errors', $analysisResult->getTotalErrorsCount());
        if ($warningsCount > 0) {
            $finalMessage .= sprintf($warningsCount === 1 ? ' and %d warning' : ' and %d warnings', $warningsCount);
        }

        if ($analysisResult->getTotalErrorsCount() > 0) {
            $style->error($finalMessage);
        } else {
            $style->warning($finalMessage);
        }

        return $analysisResult->getTotalErrorsCount() > 0 ? 1 : 0;
    }
}
