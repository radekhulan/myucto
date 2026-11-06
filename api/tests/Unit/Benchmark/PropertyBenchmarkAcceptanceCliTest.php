<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Benchmark;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PropertyBenchmarkAcceptanceCliTest extends TestCase
{
    public static function outcomes(): array
    {
        return [
            'accepted' => [true, true, 0],
            'limits exceeded' => [true, false, 2],
            'incomplete' => [false, true, 2],
            'incomplete and rejected' => [false, false, 2],
        ];
    }

    #[DataProvider('outcomes')]
    public function testCliExitStatusReflectsTheAcceptanceResult(bool $complete, bool $passed, int $expected): void
    {
        $directory = sys_get_temp_dir() . '/myucto-acceptance-cli-' . bin2hex(random_bytes(12));
        $support = $directory . '/api/bin/Support';
        mkdir($support, 0700, true);
        mkdir($directory . '/api/vendor', 0700);
        $files = [];
        try {
            $script = $directory . '/api/bin/benchmark-property-acceptance.php';
            copy(dirname(__DIR__, 3) . '/bin/benchmark-property-acceptance.php', $script);
            $files[] = $script;
            $autoload = $directory . '/api/vendor/autoload.php';
            file_put_contents($autoload, '<?php namespace MyInvoice\Infrastructure\Database; final class Connection {} '
                . 'namespace MyInvoice; final class Bootstrap { public static function buildContainer(): object {'
                . 'return new class { public function get(string $class): object { return new $class(); } }; } }');
            $files[] = $autoload;
            foreach (['PropertyBenchmarkDataset', 'PropertyBenchmarkRead', 'PropertyBenchmarkQueries', 'PropertyBenchmarkAdoption'] as $class) {
                $file = $support . '/' . $class . '.php';
                file_put_contents($file, '<?php');
                $files[] = $file;
            }
            $result = ['complete' => $complete, 'phase' => 'pilot', 'subjects' => [[]],
                'all_saldo_pages_within_1s' => $passed, 'monthly_generation_within_30s' => $passed,
                'phase_acceptance_passed' => $passed, 'full_acceptance_claimed' => false];
            $file = $support . '/PropertyBenchmarkAcceptance.php';
            file_put_contents($file, '<?php namespace MyInvoice\Benchmark; final class PropertyBenchmarkAcceptance {'
                . 'public function __construct(mixed ...$arguments) {} public function run(string $phase, string $output): array {'
                . 'return ' . var_export($result, true) . '; } }');
            $files[] = $file;
            $process = proc_open([PHP_BINARY, $script, '--phase=pilot', '--output=' . $directory],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $directory, null, ['bypass_shell' => true]);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            self::assertSame('', $error);
            self::assertSame($complete, json_decode($output, true, 512, JSON_THROW_ON_ERROR)['complete']);
            self::assertSame($passed, json_decode($output, true, 512, JSON_THROW_ON_ERROR)['phase_acceptance_passed']);
            self::assertSame($expected, $exit);
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            foreach ([$support, $directory . '/api/bin', $directory . '/api/vendor', $directory . '/api', $directory] as $path) {
                rmdir($path);
            }
        }
    }
}
