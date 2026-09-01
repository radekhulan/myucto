<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use RuntimeException;
use Throwable;

final class DumpDefinerSanitizer
{
    private const ACCOUNT = <<<'REGEX'
(?:`(?:``|[^`])+`@`(?:``|[^`])+`|'(?:''|[^'])+'@'(?:''|[^'])+'|[^\s*;]+)
REGEX;

    public static function sanitize(string $sql): string
    {
        $versioned = '~(\/\*!\d{5,6}\s+)DEFINER\s*=\s*' . self::ACCOUNT . '\s*~i';
        $direct = '~^(\s*CREATE(?:\s+OR\s+REPLACE)?(?:\s+ALGORITHM\s*=\s*[^\s]+)?\s+)DEFINER\s*=\s*'
            . self::ACCOUNT . '\s+~im';

        $withoutVersioned = preg_replace($versioned, '$1', $sql);
        if ($withoutVersioned === null) {
            throw new RuntimeException('Nelze odstranit verzovaný DEFINER z databázového dumpu.');
        }

        $sanitized = preg_replace($direct, '$1', $withoutVersioned);
        if ($sanitized === null) {
            throw new RuntimeException('Nelze odstranit DEFINER z databázového dumpu.');
        }

        return $sanitized;
    }

    public static function sanitizeFile(string $path): void
    {
        $input = @fopen($path, 'rb');
        if ($input === false) {
            throw new RuntimeException("Nelze otevřít databázový dump: $path");
        }

        $suffix = bin2hex(random_bytes(8));
        $temporary = $path . '.sanitize-' . $suffix;
        $backup = $path . '.original-' . $suffix;
        $output = @fopen($temporary, 'xb');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException("Nelze vytvořit dočasný databázový dump: $temporary");
        }

        $writeError = null;
        try {
            while (($line = fgets($input)) !== false) {
                $sanitized = self::sanitize($line);
                $length = strlen($sanitized);
                $offset = 0;
                while ($offset < $length) {
                    $written = fwrite($output, substr($sanitized, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException("Nelze zapsat sanitizovaný databázový dump: $temporary");
                    }
                    $offset += $written;
                }
            }
            if (!feof($input)) {
                throw new RuntimeException("Nelze přečíst databázový dump: $path");
            }
            if (!fflush($output)) {
                throw new RuntimeException("Nelze dokončit zápis sanitizovaného databázového dumpu: $temporary");
            }
        } catch (Throwable $e) {
            $writeError = $e;
        } finally {
            fclose($input);
            fclose($output);
        }
        if ($writeError !== null) {
            @unlink($temporary);
            throw $writeError;
        }

        try {
            if (!@rename($path, $backup)) {
                throw new RuntimeException("Nelze připravit databázový dump k nahrazení: $path");
            }
            if (!@rename($temporary, $path)) {
                @rename($backup, $path);
                throw new RuntimeException("Nelze nahradit databázový dump sanitizovanou verzí: $path");
            }
            @unlink($backup);
        } catch (Throwable $e) {
            @unlink($temporary);
            throw $e;
        }
    }
}
