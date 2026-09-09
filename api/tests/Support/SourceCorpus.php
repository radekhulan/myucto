<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

final class SourceCorpus
{
    private static array $inventories = [];
    private static array $contents = [];

    public static function files(string $directory, string $suffix = '.php'): array
    {
        $directory = str_replace('\\', '/', rtrim($directory, '/\\'));
        $key = $directory . "\0" . $suffix;
        if (isset(self::$inventories[$key])) {
            return self::$inventories[$key];
        }
        foreach (self::$inventories as $existingKey => $files) {
            [$parent, $existingSuffix] = explode("\0", $existingKey);
            if ($existingSuffix === $suffix && str_starts_with($directory, $parent . '/')) {
                return self::$inventories[$key] = array_values(array_filter(
                    $files,
                    static fn (string $path): bool => str_starts_with($path, $directory . '/'),
                ));
            }
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $directory,
            \FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($files, SORT_STRING);
        return self::$inventories[$key] = $files;
    }

    public static function read(string $path): string
    {
        $path = str_replace('\\', '/', realpath($path) ?: $path);
        if (!array_key_exists($path, self::$contents)) {
            $source = file_get_contents($path);
            if ($source === false) {
                throw new \RuntimeException('Cannot read source: ' . $path);
            }
            self::$contents[$path] = $source;
        }
        return self::$contents[$path];
    }

    public static function sources(string $directory): array
    {
        $sources = [];
        $root = str_replace('\\', '/', rtrim($directory, '/\\')) . '/';
        foreach (self::files($directory) as $path) {
            $sources[substr($path, strlen($root))] = self::read($path);
        }
        return $sources;
    }
}
