<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use MyInvoice\Tests\Support\ParallelRuntime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__) . '/Support/ParallelRuntime.php';

final class ParallelRuntimeTest extends TestCase
{
    public function testWorkersAndRunsKeepIndependentArtifactsAndRestoreRuntime(): void
    {
        $root = ParallelRuntime::createRoot(bin2hex(random_bytes(6)));
        $other = ParallelRuntime::createRoot(bin2hex(random_bytes(6)));
        try {
            $script = 'require $argv[1]; '
                . '\\MyInvoice\\Tests\\Support\\ParallelRuntime::activate(); '
                . '$path = getenv("MYINVOICE_DATA_DIR"); '
                . 'file_put_contents($path . "/storage/export.zip", "worker-" . getenv("TEST_TOKEN")); '
                . 'putenv("MYINVOICE_DATA_DIR"); '
                . '\\MyInvoice\\Tests\\Support\\ParallelRuntime::restore(); '
                . 'if (getenv("MYINVOICE_DATA_DIR") !== $path) { exit(3); } '
                . 'echo $path;';
            $processes = [];
            foreach ([$root, $other] as $run) {
                foreach (['1', '2'] as $token) {
                    $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__) . '/Support/ParallelRuntime.php'], null, [
                        'MYINVOICE_PARALLEL_RUNTIME_ROOT' => $run,
                        'MYINVOICE_PARALLEL_TEMP_BASE' => sys_get_temp_dir(),
                        'TEST_TOKEN' => $token,
                    ]);
                    $process->start();
                    $processes[] = $process;
                }
            }
            $paths = [];
            foreach ($processes as $process) {
                self::assertSame(0, $process->wait(), $process->getErrorOutput());
                $paths[] = $process->getOutput();
            }
            self::assertCount(4, array_unique($paths));
            unlink($root . '/worker-1/storage/export.zip');
            self::assertSame('worker-2', file_get_contents($root . '/worker-2/storage/export.zip'));
            ParallelRuntime::removeRoot($root);
            self::assertSame('worker-1', file_get_contents($other . '/worker-1/storage/export.zip'));
        } finally {
            if (is_dir($root)) {
                ParallelRuntime::removeRoot($root);
            }
            ParallelRuntime::removeRoot($other);
        }
    }

    public function testCleanupRejectsAnUnownedDirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        ParallelRuntime::removeRoot(sys_get_temp_dir());
    }

    public function testRejectsTraversalTokenBeforeCreatingWorker(): void
    {
        $root = ParallelRuntime::createRoot(bin2hex(random_bytes(6)));
        try {
            $process = new Process([PHP_BINARY, '-r',
                'require $argv[1]; \\MyInvoice\\Tests\\Support\\ParallelRuntime::activate();',
                dirname(__DIR__) . '/Support/ParallelRuntime.php',
            ], null, ['MYINVOICE_PARALLEL_RUNTIME_ROOT' => $root, 'TEST_TOKEN' => '../outside']);
            self::assertNotSame(0, $process->run());
            self::assertSame([], array_values(array_diff(scandir($root), ['.', '..'])));
        } finally {
            ParallelRuntime::removeRoot($root);
        }
    }
}
