<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Bootstrap;
use PDO;
use PHPUnit\Framework\Assert;

final class NativePropertyReset
{
    public static function run(string $schema, bool $keep): array
    {
        Assert::assertMatchesRegularExpression('/^myucto_(workflow_clone|s5_lifecycle|clonesandbox)_[a-f0-9]{10}_test$/D', $schema);
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $schema . '_reset_' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $env = getenv();
            $env['MYINVOICE_DB_NAME'] = $schema;
            $env['MYINVOICE_DATA_DIR'] = $dir;
            $command = [PHP_BINARY, Bootstrap::rootDir() . '/api/bin/reset.php', '--yes', '--skip-files'];
            if ($keep) $command[] = '--keep-users-supplier';
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $dir . '/output.log', 'w'], 2 => ['file', $dir . '/error.log', 'w']], $pipes, Bootstrap::rootDir(), $env);
            Assert::assertIsResource($process);
            fclose($pipes[0]);
            $exit = proc_close($process);
            return [$exit, file_get_contents($dir . '/output.log') . file_get_contents($dir . '/error.log')];
        } finally {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($dir);
        }
    }

    public static function assertCleared(PDO $pdo): void
    {
        $checked = 0;
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (!str_starts_with($table, 'unit_') && !str_starts_with($table, 'property_')) continue;
            Assert::assertMatchesRegularExpression('/^[a-z_]+$/D', $table);
            Assert::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn(), $table);
            ++$checked;
        }
        Assert::assertGreaterThanOrEqual(24, $checked);
    }
}
