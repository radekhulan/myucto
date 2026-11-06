<?php

declare(strict_types=1);

namespace MyUctoRegression;

final class Safety
{
    public static function database(string $name, string $working): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*_[a-f0-9]{8,32}_test$/D', $name) || strcasecmp($name, $working) === 0) {
            throw new \RuntimeException('test_database_required');
        }
    }

    public static function host(string $host, ?string $dockerHost): void
    {
        if (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            return;
        }
        if ($dockerHost !== $host || getenv('MYINVOICE_REGRESSION_ALLOW_DOCKER_TEST') !== '1' || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/D', $host)) {
            throw new \RuntimeException('local_test_host_required');
        }
    }

    public static function outside(string $path, array $roots): string
    {
        $resolved = realpath($path);
        if ($resolved === false) {
            $parent = realpath(dirname($path));
            if ($parent === false || in_array(basename($path), ['.', '..', ''], true)) {
                throw new \RuntimeException('existing_output_parent_required');
            }
            $resolved = $parent . DIRECTORY_SEPARATOR . basename($path);
        }
        $target = strtolower(str_replace('\\', '/', $resolved));
        foreach ($roots as $root) {
            $canonical = realpath($root);
            if ($canonical === false) {
                throw new \RuntimeException('invalid_code_root');
            }
            $canonical = strtolower(rtrim(str_replace('\\', '/', $canonical), '/'));
            if ($target === $canonical || str_starts_with($target, $canonical . '/')) {
                throw new \RuntimeException('output_inside_repository');
            }
        }
        $ancestor = is_dir($resolved) ? $resolved : dirname($resolved);
        while (true) {
            if (file_exists($ancestor . '/.git')) {
                throw new \RuntimeException('output_inside_repository');
            }
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                break;
            }
            $ancestor = $parent;
        }
        return $resolved;
    }

    public static function writeJson(string $path, array $data): void
    {
        $file = fopen($path, 'x');
        if ($file === false) {
            throw new \RuntimeException('output_exists_or_unwritable');
        }
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
            if (fwrite($file, $json) !== strlen($json)) {
                throw new \RuntimeException('output_write_failed');
            }
        } finally {
            fclose($file);
        }
    }

    public static function date(string $date): string
    {
        $value = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$value || $value->format('Y-m-d') !== $date) {
            throw new \RuntimeException('invalid_date');
        }
        return $date;
    }
}
