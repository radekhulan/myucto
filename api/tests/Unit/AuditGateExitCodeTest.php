<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AuditGateExitCodeTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = str_replace('\\', '/', sys_get_temp_dir()) . '/myucto-audit-gate-' . bin2hex(random_bytes(8));
        foreach (['cmd', 'api/bin', 'private/scripts'] as $dir) {
            mkdir($this->fixture . '/' . $dir, 0775, true);
        }
        $root = dirname(__DIR__, 3);
        foreach (['ps1', 'sh'] as $extension) {
            $source = (string) file_get_contents($root . '/cmd/audit-gate.' . $extension);
            file_put_contents($this->fixture . '/cmd/audit-gate.' . $extension, str_replace("\r\n", "\n", $source));
        }
        copy(dirname(__DIR__) . '/Fixtures/audit-gate-runner.php', $this->fixture . '/runner.php');
        file_put_contents($this->fixture . '/fake-php.ps1', <<<'PS1'
& $env:AUDIT_GATE_REAL_PHP (Join-Path $env:AUDIT_GATE_FIXTURE 'runner.php') @args
exit $LASTEXITCODE
PS1);
        file_put_contents($this->fixture . '/fake-php.sh', <<<'SH'
#!/usr/bin/env bash
exec "$AUDIT_GATE_REAL_PHP" "$AUDIT_GATE_FIXTURE/runner.php" "$@"
SH);
        chmod($this->fixture . '/fake-php.sh', 0755);
    }

    protected function tearDown(): void
    {
        if (!isset($this->fixture)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->fixture, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->fixture);
    }

    public static function scenarios(): iterable
    {
        $cases = [
            'AuditCore error two is failure' => [1, 2, 0, true, false, true, 1],
            'full error two overrides green cache' => [0, 2, 0, true, true, true, 1],
            'test error and empty data stay independent' => [1, 2, 2, false, false, true, 1],
            'empty data stays unverified' => [1, 0, 2, false, false, true, 0],
            'data failure stays failure' => [1, 0, 1, false, false, true, 1],
            'skip data runs no data checks' => [1, 0, 1, true, false, true, 0],
            'green cache skips test execution' => [0, 2, 0, true, false, true, 0],
            'green cache still checks data' => [0, 2, 2, false, false, true, 0],
            'core failure in full suite fails without rerun' => [2, 0, 0, true, false, true, 1],
            'core failure in full suite still checks data' => [2, 0, 0, false, false, true, 1],
            'full run overrides core failure of full suite' => [2, 0, 0, true, true, true, 0],
            'missing optional scripts are skipped' => [1, 0, 2, false, false, false, 0],
            'successful tests and data pass' => [1, 0, 0, false, false, true, 0],
        ];
        foreach (['ps1', 'sh'] as $extension) {
            foreach ($cases as $name => $case) {
                yield $extension . ': ' . $name => [$extension, ...$case];
            }
        }
    }

    #[DataProvider('scenarios')]
    public function testExitContract(
        string $extension,
        int $green,
        int $tests,
        int $data,
        bool $skipData,
        bool $full,
        bool $optional,
        int $expectedExit,
    ): void {
        $shell = $extension === 'ps1' ? 'pwsh' : (getenv('MYINVOICE_TEST_BASH_BIN') ?: 'bash');
        if ($extension === 'sh' && PHP_OS_FAMILY === 'Windows' && !getenv('MYINVOICE_TEST_BASH_BIN')) {
            $git = (new \Symfony\Component\Process\ExecutableFinder())->find('git');
            $gitBash = $git === null ? null : dirname($git, 2) . '/bin/bash.exe';
            if ($gitBash !== null && is_file($gitBash)) $shell = $gitBash;
        }
        $probe = new Process($extension === 'ps1' ? [$shell, '-NoProfile', '-Command', 'exit 0'] : [$shell, '--version']);
        $probe->run();
        if (!$probe->isSuccessful()) {
            if (($extension === 'ps1') === (PHP_OS_FAMILY === 'Windows')) {
                self::fail('Native audit shell unavailable: ' . $probe->getErrorOutput());
            }
            self::markTestSkipped('Optional audit shell is unavailable: ' . $shell);
        }
        file_put_contents($this->fixture . '/scenario.json', json_encode(compact('green', 'tests', 'data'), JSON_THROW_ON_ERROR));
        if ($optional) {
            foreach (['compare_dph.php', 'checks_shape_sweep.php'] as $file) {
                file_put_contents($this->fixture . '/private/scripts/' . $file, '<?php throw new RuntimeException("Must never execute");');
            }
        }
        $command = $extension === 'ps1'
            ? [$shell, '-NoProfile', '-File', 'cmd/audit-gate.ps1', '-Database', 'audit_gate_data_test']
            : [$shell, 'cmd/audit-gate.sh', '--database=audit_gate_data_test'];
        if ($skipData) {
            $command[] = $extension === 'ps1' ? '-SkipData' : '--skip-data';
        }
        if ($full) {
            $command[] = $extension === 'ps1' ? '-Full' : '--full';
        }
        $process = new Process($command, $this->fixture, [
            'MYINVOICE_PHP_BIN' => $this->fixture . '/fake-php.' . $extension,
            'MYINVOICE_DB_NAME' => 'audit_gate_runner_test',
            'AUDIT_GATE_REAL_PHP' => str_replace('\\', '/', PHP_BINARY),
            'AUDIT_GATE_FIXTURE' => $this->fixture,
        ]);
        $process->setTimeout(30);
        $process->run();
        $output = $process->getOutput() . $process->getErrorOutput();
        $calls = array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($this->fixture . '/calls.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
        );
        $testCalls = array_values(array_filter($calls, static fn (array $call): bool => $call['script'] === 'test-parallel.php' && !$call['green_check']));
        $dataCalls = array_values(array_filter($calls, static fn (array $call): bool => $call['script'] !== 'test-parallel.php'));
        // green = výsledek --audit-check: 0 jádro bez pádu, 1 běh plné sady chybí, 2 jádro padlo.
        self::assertCount($full || $green === 1 ? 1 : 0, $testCalls, $output);
        if ($testCalls !== []) {
            self::assertSame('audit_gate_runner_test', $testCalls[0]['database']);
            self::assertSame($this->fixture . '/api', $testCalls[0]['cwd']);
            self::assertSame($full ? ['--rerun-failures'] : ['--testsuite=AuditCore', '--rerun-failures'], $testCalls[0]['args']);
        }
        self::assertSame($skipData ? [] : array_merge(
            ['check-invariants.php', 'cross-check.php', 'check-schema.php'],
            $optional ? ['compare_dph.php', 'checks_shape_sweep.php'] : [],
        ), array_column($dataCalls, 'script'), $output);
        foreach ($dataCalls as $call) {
            self::assertSame('audit_gate_data_test', $call['database']);
            self::assertSame($this->fixture . '/api', $call['cwd']);
        }
        self::assertSame($expectedExit, $process->getExitCode(), $output);
        if ($expectedExit !== 0) {
            self::assertStringContainsString('Auditní brána: SELHALO', $output);
            self::assertStringNotContainsString('VŠE PROŠLO', $output);
        } else {
            self::assertStringContainsString('VŠE PROŠLO', $output);
        }
        self::assertSame(!$skipData && $data === 2, str_contains($output, 'NEOVĚŘENO'), $output);
        if (!$skipData && !$optional) {
            self::assertSame(2, substr_count($output, '(private/scripts chybí)'));
        }
    }
}
