<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

\defined('MYINVOICE_PARALLEL_RUNNER_FUNCTIONS_ONLY') || \define('MYINVOICE_PARALLEL_RUNNER_FUNCTIONS_ONLY', true);
require_once dirname(__DIR__, 2) . '/bin/test-parallel.php';

final class ParallelShardTest extends TestCase
{
    #[TestWith([false, null])]
    #[TestWith([false, ''])]
    public function testOnlyTheCompleteUnshardedRunCanWriteFullSuiteResults(bool $application, ?string $suite): void
    {
        $selection = \parallelTestSelection($application, $suite);
        self::assertTrue($selection['full_suite']);
        self::assertTrue($selection['http_tail']);
        self::assertNull($selection['shard']);
        self::assertSame([], $selection['arguments']);
    }

    #[TestWith([true, null])]
    #[TestWith([false, 'Application'])]
    public function testApplicationShardsPartitionHttpTailAndNeverRecordTheFullSuite(bool $application, ?string $suite): void
    {
        $first = \parallelTestSelection($application, $suite, '1/2');
        $second = \parallelTestSelection($application, $suite, '2/2');
        self::assertTrue($first['http_tail']);
        self::assertFalse($second['http_tail']);
        self::assertFalse($first['full_suite']);
        self::assertFalse($second['full_suite']);
        self::assertSame(['--shard=1/2', '--shard-test-distribution=round-robin'], $first['arguments']);
        self::assertSame(['--shard=2/2', '--shard-test-distribution=round-robin'], $second['arguments']);
        self::assertSame('1/2', $first['shard']);
        self::assertSame('2/2', $second['shard']);
    }

    #[TestWith([false])]
    #[TestWith([''])]
    #[TestWith(['0/2'])]
    #[TestWith(['1/0'])]
    #[TestWith(['1/1'])]
    #[TestWith(['3/2'])]
    #[TestWith(['-1/2'])]
    #[TestWith(['1/02'])]
    #[TestWith(['01/2'])]
    #[TestWith(['1/2/3'])]
    #[TestWith(['1/2x'])]
    #[TestWith([' 1/2'])]
    #[TestWith(['1/999999999999999999999999999999'])]
    #[TestWith([['1/2', '2/2']])]
    public function testRejectsMalformedOrOutOfRangeShard(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \parallelTestSelection(true, null, $value);
    }

    #[TestWith([null])]
    #[TestWith(['Architecture'])]
    #[TestWith(['Invariants'])]
    #[TestWith(['AuditCore'])]
    public function testShardCannotSilentlyChangeTheScopeOfAnAuditOrFullRun(?string $suite): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--shard lze použít pouze');
        \parallelTestSelection(false, $suite, '1/2');
    }

    public function testCliRejectsShardWithoutApplicationBeforeConnectingToTheDatabase(): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/test-parallel.php', '--shard=1/2'], null, ['MYINVOICE_DB_NAME' => 'invalid_shard_must_never_connect']);
        self::assertSame(2, $process->run());
        self::assertSame('', $process->getOutput());
        self::assertStringContainsString('--shard lze použít pouze', $process->getErrorOutput());
    }

    #[TestWith(['--shard'])]
    #[TestWith(['--shard='])]
    #[TestWith(['--shard=2/1'])]
    #[TestWith(['--shard=1/1'])]
    public function testCliRejectsMissingOrInvalidShardValueBeforeDatabasePreparation(string $argument): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/test-parallel.php', '--application', $argument], null, ['MYINVOICE_DB_NAME' => 'invalid_shard_must_never_connect']);
        self::assertSame(2, $process->run());
        self::assertSame('', $process->getOutput());
        self::assertStringContainsString('--shard vyžaduje K/N', $process->getErrorOutput());
    }

    #[TestWith(['--green-check'])]
    #[TestWith(['--audit-check'])]
    public function testShardedRunCannotConsultTheStampOfACompleteRun(string $argument): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/bin/test-parallel.php', '--application', '--shard=1/2', $argument], null, ['MYINVOICE_DB_NAME' => 'invalid_shard_must_never_connect']);
        self::assertSame(2, $process->run());
        self::assertStringContainsString('--shard nelze kombinovat', $process->getErrorOutput());
    }

    #[TestWith([2])]
    #[TestWith([4])]
    public function testRealParatestShardsKeepClassesWholeAndTheirUnionMatchesTheCompleteRun(int $parts): void
    {
        $directory = sys_get_temp_dir() . '/myucto-shard-fixture-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $cases = $directory . '/cases';
            mkdir($cases, 0700);
            foreach (range('A', 'G') as $letter) {
                file_put_contents($cases . '/Case' . $letter . 'Test.php', str_replace('FIXTURE_CLASS', 'Case' . $letter . 'Test', <<<'PHP'
<?php
namespace SyntheticParallelShard;
final class FIXTURE_CLASS extends \PHPUnit\Framework\TestCase
{
    public function testFirst(): void { self::assertTrue(true); }
    #[\PHPUnit\Framework\Attributes\TestWith([1])]
    #[\PHPUnit\Framework\Attributes\TestWith([2])]
    #[\PHPUnit\Framework\Attributes\TestWith([3])]
    public function testProvider(int $value): void { self::assertGreaterThan(0, $value); }
}
PHP));
            }
            $escaped = htmlspecialchars(str_replace('\\', '/', $directory), ENT_QUOTES | ENT_XML1, 'UTF-8');
            file_put_contents($directory . '/phpunit.xml', '<?xml version="1.0" encoding="UTF-8"?>'
                . '<phpunit cacheDirectory="' . $escaped . '/cache"><testsuites><testsuite name="Application">'
                . '<directory>' . $escaped . '/cases</directory></testsuite></testsuites></phpunit>');
            $complete = $this->runParatest($directory, null);
            self::assertCount(28, $complete['tests']);
            self::assertCount(7, $complete['classes']);
            $all = [];
            $seenClasses = [];
            for ($part = 1; $part <= $parts; ++$part) {
                $shard = $this->runParatest($directory, $part . '/' . $parts);
                self::assertCount(intdiv(7, $parts) + ($part <= 7 % $parts ? 1 : 0), $shard['classes']);
                self::assertSame([], array_values(array_intersect($seenClasses, $shard['classes'])));
                self::assertSame([], array_values(array_intersect($all, $shard['tests'])));
                $all = array_merge($all, $shard['tests']);
                $seenClasses = array_merge($seenClasses, $shard['classes']);
                foreach ($shard['classes'] as $class) {
                    self::assertCount(4, array_filter($shard['tests'], static fn (string $test): bool => str_starts_with($test, $class . '::')));
                }
            }
            sort($all);
            self::assertSame($complete['tests'], $all);
        } finally {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }

    private function runParatest(string $directory, ?string $shard): array
    {
        $report = $directory . '/result-' . ($shard === null ? 'complete' : str_replace('/', '-', $shard)) . '.xml';
        $selection = \parallelTestSelection(true, null, $shard);
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/vendor/brianium/paratest/bin/paratest',
            '--configuration=' . $directory . '/phpunit.xml',
            '--testsuite=Application',
            '--processes=2',
            '--order-by=duration-descending',
            ...$selection['arguments'],
            '--log-junit=' . $report,
        ], dirname(__DIR__, 2));
        $process->setTimeout(60);
        self::assertSame(0, $process->run(), $process->getOutput() . $process->getErrorOutput());
        $xml = simplexml_load_file($report);
        self::assertNotFalse($xml);
        self::assertSame([], $xml->xpath('//testcase[failure or error or skipped]'));
        $tests = [];
        $classes = [];
        foreach ($xml->xpath('//testcase') ?: [] as $test) {
            $class = (string) ($test['class'] ?? $test['classname'] ?? '');
            self::assertNotSame('', $class);
            $tests[] = $class . '::' . (string) $test['name'];
            $classes[$class] = true;
        }
        sort($tests);
        $classes = array_keys($classes);
        sort($classes);
        return ['tests' => $tests, 'classes' => $classes];
    }
}
