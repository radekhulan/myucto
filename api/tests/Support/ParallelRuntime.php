<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

final class ParallelRuntime
{
    private static ?string $workerPath = null;

    public static function createRoot(string $runId): string
    {
        if (preg_match('/^[a-f0-9]{12}$/D', $runId) !== 1) {
            throw new \RuntimeException('Neplatné ID testovacího běhu.');
        }
        $path = rtrim(sys_get_temp_dir(), '/\\') . '/myinvoice-tests-' . $runId;
        if (!mkdir($path, 0700)) {
            throw new \RuntimeException('Nelze vytvořit runtime testovacího běhu.');
        }
        return $path;
    }

    public static function activate(): void
    {
        $root = getenv('MYINVOICE_PARALLEL_RUNTIME_ROOT');
        if (!is_string($root) || $root === '') {
            return;
        }
        self::assertRoot($root);
        $token = getenv('TEST_TOKEN');
        $token = is_string($token) && $token !== '' ? $token : 'controller';
        if (preg_match('/^[A-Za-z0-9_]+$/D', $token) !== 1) {
            throw new \RuntimeException('Neplatný runtime token workeru.');
        }
        self::$workerPath = $root . '/worker-' . $token;
        foreach (['storage', 'log', 'tmp'] as $directory) {
            $path = self::$workerPath . '/' . $directory;
            if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
                throw new \RuntimeException('Nelze vytvořit runtime workeru.');
            }
        }
        self::restore();
    }

    public static function restore(): void
    {
        if (self::$workerPath === null) {
            return;
        }
        foreach (['MYINVOICE_DATA_DIR' => self::$workerPath, 'TMPDIR' => self::$workerPath . '/tmp',
            'TMP' => self::$workerPath . '/tmp', 'TEMP' => self::$workerPath . '/tmp'] as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    public static function removeRoot(string $root): void
    {
        self::assertRoot($root);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                if (!rmdir($entry->getPathname())) {
                    throw new \RuntimeException('Nelze odstranit runtime adresář.');
                }
            } elseif (!unlink($entry->getPathname())) {
                throw new \RuntimeException('Nelze odstranit runtime soubor.');
            }
        }
        if (!rmdir($root)) {
            throw new \RuntimeException('Nelze odstranit runtime kořen.');
        }
    }

    private static function assertRoot(string $root): void
    {
        $resolved = realpath($root);
        if ($resolved === false || is_link($root)
            || preg_match('/^myinvoice-tests-[a-f0-9]{12}$/D', basename($root)) !== 1
            || (strcasecmp((string) realpath(dirname($root)), (string) realpath(sys_get_temp_dir())) !== 0
                && strcasecmp((string) realpath(dirname($root)), (string) realpath((string) getenv('MYINVOICE_PARALLEL_TEMP_BASE'))) !== 0)) {
            throw new \RuntimeException('Runtime kořen není vlastní dočasný adresář testů.');
        }
    }
}
