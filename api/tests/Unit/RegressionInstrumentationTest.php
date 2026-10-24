<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use MyUctoRegression\Network;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/regression/bootstrap.php';

final class RegressionInstrumentationTest extends TestCase
{
    public function testIsolatedConfigPreservesInstanceOptionsAndCredentialsInMemory(): void
    {
        self::assertSame(array_fill(0, 8, true), self::probe('config-snapshot')['preserved']);
    }

    public function testMutatedEnvironmentHelperAndLoaderSourcesAreStillRejected(): void
    {
        self::assertSame([true, true], self::probe('config-source-mutated')['rejected']);
    }

    public function testOriginalAndGuardedSqliteSelectNullAndTransactionAreIdentical(): void
    {
        $original = self::probe('sqlite-original');
        $guarded = self::probe('sqlite-guarded');
        self::assertSame($original, $guarded);
        self::assertSame('12345678901234567890.1234567890', $guarded['rows'][0]['amount']);
        self::assertNull($guarded['rows'][0]['nullable']);
        self::assertFalse($guarded['transaction']);
    }

    public function testCaughtCurlAndSuppressedStreamAttemptsRemainVisible(): void
    {
        self::assertSame(['network_or_process_attempt', 'network_stream_attempt'], self::probe('network')['violations']);
    }

    public function testUnknownConnectionSourceIsRejectedBeforeConnecting(): void
    {
        self::assertTrue(self::probe('unknown-signature')['rejected']);
    }

    public function testSavepointSyntaxOutsideNativeSymbolIsRejected(): void
    {
        self::assertSame(array_fill(0, 17, true), self::probe('savepoint-syntax')['rejected']);
    }

    public function testParenthesizedUnionOutsideNativeSymbolIsRejected(): void
    {
        self::assertSame(array_fill(0, 12, true), self::probe('union-syntax')['rejected']);
    }

    public function testApprovedOldDatabaseSourcesRemainEquivalentUnderCurrentInstrumentation(): void
    {
        $original = self::approvedProbe('original');
        $guarded = self::approvedProbe('guarded');
        self::assertSame($original, $guarded);
        self::assertSame(self::probe('sqlite-original')['rows'], $guarded['rows']);
        self::assertSame(['driver'=>'sqlite','error_mode'=>\PDO::ERRMODE_EXCEPTION,'fetch_mode'=>\PDO::FETCH_ASSOC,'foreign_keys'=>0,'transaction'=>true], $guarded['session']);
        self::assertFalse($guarded['transaction']);
    }

    public function testMutatedApprovedLoggingSourceIsStillRejected(): void
    {
        self::assertTrue(self::approvedProbe('mutated')['rejected']);
    }

    private static function approvedProbe(string $mode): array
    {
        $command = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'disable_functions=' . implode(',', Network::FUNCTIONS), dirname(__DIR__) . '/Fixtures/regression-approved-database-probe.php', $mode];
        $process = proc_open($command, [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertSame('', $stderr);
        self::assertSame(0, $exit, 'Approved-source probe failed: ' . $stdout);
        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function probe(string $mode, string $database = ''): array
    {
        $command = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'disable_functions=' . implode(',', Network::FUNCTIONS), dirname(__DIR__) . '/Fixtures/regression-probe.php', $mode, $database];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertSame('', $stderr, 'Probe must not emit raw errors.');
        self::assertSame(0, $exit, 'Probe failed with sanitized output: ' . $stdout);
        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }
}
