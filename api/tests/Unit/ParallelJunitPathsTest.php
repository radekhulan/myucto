<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use PHPUnit\Framework\TestCase;

\defined('MYINVOICE_PARALLEL_RUNNER_FUNCTIONS_ONLY') || \define('MYINVOICE_PARALLEL_RUNNER_FUNCTIONS_ONLY', true);
require_once dirname(__DIR__, 2) . '/bin/test-parallel.php';

final class ParallelJunitPathsTest extends TestCase
{
    public function testRelativeReportUsesInvocationDirectoryAndKeepsHttpSeparate(): void
    {
        $directory = sys_get_temp_dir() . '/parallel-junit-' . bin2hex(random_bytes(6));
        try {
            $paths = \junitLogPaths('reports/results.xml', $directory);
            $expected = realpath($directory . '/reports');
            self::assertNotFalse($expected);
            self::assertSame($expected . DIRECTORY_SEPARATOR . 'results.xml', $paths['parallel']);
            self::assertSame($expected . DIRECTORY_SEPARATOR . 'results.http.xml', $paths['http']);
            file_put_contents($paths['parallel'], 'application');
            file_put_contents($paths['http'], 'http');
            self::assertSame('application', file_get_contents($paths['parallel']));
            self::assertSame('http', file_get_contents($paths['http']));
        } finally {
            if (isset($paths)) {
                foreach ($paths as $path) {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }
            if (is_dir($directory . '/reports')) {
                rmdir($directory . '/reports');
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testAbsoluteReportDoesNotDependOnChildWorkingDirectory(): void
    {
        $directory = sys_get_temp_dir() . '/parallel-junit-' . bin2hex(random_bytes(6));
        try {
            $paths = \junitLogPaths($directory . '/results', dirname(__DIR__, 2));
            self::assertSame(realpath($directory) . DIRECTORY_SEPARATOR . 'results', $paths['parallel']);
            self::assertSame($paths['parallel'] . '.http.xml', $paths['http']);
        } finally {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testRejectsMissingOptionValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \junitLogPaths(false, (string) getcwd());
    }

    public function testRejectsDriveRelativePath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \junitLogPaths('C:results.xml', (string) getcwd());
    }

    public function testRejectsDirectoryAsReportTarget(): void
    {
        $this->expectException(\RuntimeException::class);
        \junitLogPaths(sys_get_temp_dir(), (string) getcwd());
    }

    public function testTimingsRejectsMissingOptionValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--timings vyžaduje cestu k souboru.');
        \reportOutputPath(false, (string) getcwd(), '--timings');
    }

    public function testTimingsRejectsEmptyOptionValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \reportOutputPath('', (string) getcwd(), '--timings');
    }

    public function testTimingsPathUsesInvocationDirectory(): void
    {
        $directory = sys_get_temp_dir() . '/parallel-timings-' . bin2hex(random_bytes(6));
        try {
            $path = \reportOutputPath('reports/timings.json', $directory, '--timings');
            self::assertSame(realpath($directory . '/reports') . DIRECTORY_SEPARATOR . 'timings.json', $path);
        } finally {
            if (is_dir($directory . '/reports')) {
                rmdir($directory . '/reports');
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testCliRejectsEmptyTimingsBeforeLoadingConfiguration(): void
    {
        $process = new \Symfony\Component\Process\Process([
            PHP_BINARY, dirname(__DIR__, 2) . '/bin/test-parallel.php', '--timings=',
        ]);
        self::assertSame(2, $process->run());
        self::assertStringContainsString('--timings vyžaduje cestu k souboru.', $process->getErrorOutput());
        self::assertSame('', $process->getOutput());
    }
}
