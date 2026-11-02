<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

\defined('MYINVOICE_PARALLEL_RUNNER_FUNCTIONS_ONLY') || \define('MYINVOICE_PARALLEL_RUNNER_FUNCTIONS_ONLY', true);
require_once dirname(__DIR__, 2) . '/bin/test-parallel.php';

final class ParallelWorkerBatchTest extends TestCase
{
    public function testZeroKeepsUnlimitedWorkers(): void
    {
        self::assertSame(['--max-batch-size=0'], \parallelWorkerBatchOptions(0));
    }

    public static function invalidSizes(): iterable
    {
        foreach ([-1, '1.5', 'twenty', '', false, []] as $value) yield [$value];
    }

    #[DataProvider('invalidSizes')]
    public function testInvalidSizeIsRejectedBeforeStartingWorkers(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \parallelWorkerBatchOptions($value);
    }

    public function testRealWorkersRecycleWithoutLosingTests(): void
    {
        $directory = sys_get_temp_dir() . '/parallel-batch-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $api = dirname(__DIR__, 2);
        try {
            // Samostatné testy bez aplikačního bootstrapu: měříme skutečné PID,
            // nikoli jen přítomnost přepínače ve zdrojovém kódu spouštěče.
            for ($i = 1; $i <= 8; ++$i) {
                $path = var_export($directory . '/pids.jsonl', true);
                file_put_contents($directory . '/Probe' . $i . 'Test.php', "<?php\n"
                    . "final class Probe{$i}Test extends \\PHPUnit\\Framework\\TestCase {\n"
                    . "public function testExecuted(): void {\n"
                    . "file_put_contents({$path}, json_encode([getmypid(), __CLASS__]).\"\\n\", FILE_APPEND | LOCK_EX);\n"
                    . "self::assertTrue(true);\n}}\n");
            }
            $autoload = htmlspecialchars($api . '/vendor/autoload.php', ENT_XML1);
            file_put_contents($directory . '/phpunit.xml', '<phpunit cacheResult="false" bootstrap="' . $autoload . '">'
                . '<testsuites><testsuite name="Probe"><directory>.</directory></testsuite></testsuites></phpunit>');
            $process = new Process([
                PHP_BINARY, $api . '/vendor/brianium/paratest/bin/paratest',
                '--configuration=' . $directory . '/phpunit.xml', '--processes=2',
                ...\parallelWorkerBatchOptions('2'),
            ], $directory);
            $process->setTimeout(60);
            self::assertSame(0, $process->run(), $process->getOutput() . $process->getErrorOutput());
            $executions = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                file($directory . '/pids.jsonl', FILE_IGNORE_NEW_LINES));
            self::assertCount(8, $executions);
            self::assertCount(8, array_unique(array_column($executions, 1)));
            $perProcess = array_count_values(array_column($executions, 0));
            self::assertGreaterThanOrEqual(4, count($perProcess));
            self::assertLessThanOrEqual(2, max($perProcess));
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) if (is_file($file)) unlink($file);
            rmdir($directory);
        }
    }
}
