<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
#[Group('performance')]
final class CanonicalJsonSpoolMemoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'myucto-canonical-test-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) if (is_file($path)) unlink($path);
        if (is_dir($this->directory)) rmdir($this->directory);
    }

    public function testOneThousandAndOneRowsHaveByteExactLegacyParity(): void
    {
        $input = $this->writeGraph(1_001);
        $legacy = $this->worker('legacy', $input, $this->directory . DIRECTORY_SEPARATOR . 'legacy-1001.json', '512M');
        $stream = $this->worker('stream', $input, $this->directory . DIRECTORY_SEPARATOR . 'stream-1001.json', '64M');

        self::assertSame($legacy['sha256'], $stream['sha256']);
        self::assertSame($legacy['bytes'], $stream['bytes']);
        self::assertSame(hash_file('sha256', $this->directory . DIRECTORY_SEPARATOR . 'legacy-1001.json'), hash_file('sha256', $this->directory . DIRECTORY_SEPARATOR . 'stream-1001.json'));
    }

    /**
     * 6 001 řádků dá přes 18 000 záznamů pásky, tedy víc než RUN_ITEMS × MERGE_FAN_IN
     * (2 048 × 8). Třídění tak projde dvěma průchody slévání, stejně jako u velkého archivu.
     * Paměť se měří proti legacy cestě: ta drží celý graf (u 6 001 řádků přes 6 MB),
     * proud zůstává na pár MB bez ohledu na velikost vstupu.
     */
    public function testMultiPassMergeKeepsFileAndDatabaseParityWithBoundedMemory(): void
    {
        $input = $this->writeGraph(6_001);
        $legacy = $this->worker('legacy', $input, $this->directory . DIRECTORY_SEPARATOR . 'legacy-6001.json', '512M');
        $stream = $this->worker('stream', $input, $this->directory . DIRECTORY_SEPARATOR . 'stream-6001.json', '64M');
        $database = $this->worker('database', $input, $this->directory . DIRECTORY_SEPARATOR . 'database-6001.json', '64M');

        self::assertSame($legacy['sha256'], $stream['sha256']);
        self::assertSame($legacy['sha256'], $database['sha256']);
        self::assertSame($legacy['bytes'], $stream['bytes']);
        self::assertSame($legacy['bytes'], $database['bytes']);
        self::assertGreaterThan(4 * 1024 * 1024, $legacy['peak_delta_bytes'], json_encode($legacy, JSON_THROW_ON_ERROR));
        self::assertLessThanOrEqual(4 * 1024 * 1024, $stream['peak_delta_bytes'], json_encode($stream, JSON_THROW_ON_ERROR));
        self::assertLessThanOrEqual(24 * 1024 * 1024, $database['peak_delta_bytes'], json_encode($database, JSON_THROW_ON_ERROR));
        $receipt = getenv('MYINVOICE_STREAM_RECEIPT');
        if (is_string($receipt) && $receipt !== '') {
            file_put_contents($receipt, json_encode(['rows' => 6_001, 'legacy' => $legacy, 'stream' => $stream, 'database' => $database], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
    }

    private function writeGraph(int $rows): string
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . 'input-' . $rows . '.json';
        $handle = fopen($path, 'w+b');
        self::assertIsResource($handle);
        fwrite($handle, '{"version":3,"rows":[');
        for ($row = 1; $row <= $rows; $row++) {
            if ($row > 1) fwrite($handle, ',');
            fwrite($handle, '{"z":' . $row . ',"a":"' . str_pad((string) $row, 12, '0', STR_PAD_LEFT) . '"}');
        }
        fwrite($handle, '],"schema":"synthetic-dppo-archive"}');
        fclose($handle);
        return $path;
    }

    /** @return array{mode:string,sha256:string,bytes:int,peak_delta_bytes:int,elapsed_ms:int} */
    private function worker(string $mode, string $input, string $output, string $memory): array
    {
        $command = [PHP_BINARY, '-d', 'memory_limit=' . $memory, __DIR__ . '/../../Support/CanonicalJsonStreamWorker.php', $mode, $input, $output];
        $pipes = [];
        $environment = getenv();
        self::assertIsArray($environment);
        self::assertIsString($environment['MYINVOICE_DB_NAME'] ?? null);
        self::assertStringEndsWith('_test', $environment['MYINVOICE_DB_NAME']);
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__, 3), $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertSame(0, $exit, $stderr ?: $stdout);
        $result = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        return $result;
    }
}
