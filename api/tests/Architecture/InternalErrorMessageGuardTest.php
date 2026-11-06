<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Text výjimky u 5xx odpovědi jde přes `Json::internalError()`.
 *
 * `Json::error()` u 5xx označí hlášku jako napsanou pro uživatele a
 * InternalErrorRedactionMiddleware ji pak nechá být. Kdyby přes ni šel
 * `$e->getMessage()`, dostal by se SQLSTATE, název omezení nebo cesta na
 * serveru až k uživateli. Test hledá volání `Json::error()` se stavem 5xx
 * zapsaným číslem, jehož hláška obsahuje `getMessage()`. Stavy 502, 503, 504
 * a 507 middleware nechává beze změny vždy, ty se nekontrolují.
 */
final class InternalErrorMessageGuardTest extends TestCase
{
    public function testExceptionTextIn5xxGoesThroughInternalError(): void
    {
        $violations = [];
        $internal = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $internal += substr_count($code, 'Json::internalError(');
            foreach (self::violations($code) as $line) {
                $violations[] = $file->getFilename() . ':' . $line;
            }
        }

        self::assertGreaterThan(50, $internal, 'Sběr nenašel volání Json::internalError(), rozbitý test?');
        self::assertSame([], $violations, 'Text výjimky u 5xx posílej přes Json::internalError(): ' . implode(', ', $violations));
    }

    public function testDetectorFindsExceptionTextAndIgnoresAuthoredMessages(): void
    {
        $code = <<<'PHP'
            <?php
            Json::error($r, 'save_failed', $e->getMessage(), 500);
            Json::error($r, 'pdf_failed', 'PDF selhalo: ' . $e->getMessage(), 500);
            Json::error($r, 'storage_not_writable', 'Úložiště není zapisovatelné.', 500);
            Json::error($r, 'invalid', $e->getMessage(), 422);
            Json::internalError($r, 'save_failed', $e->getMessage(), 500);
            Json::error($r, 'upstream', $e->getMessage(), 502);
            PHP;

        self::assertSame([2, 3], self::violations($code));
    }

    /** @return list<int> řádky porušení */
    private static function violations(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $out = [];
        $n = count($tokens);
        for ($i = 0; $i + 3 < $n; $i++) {
            if (!self::is($tokens[$i], 'Json') || !is_array($tokens[$i + 1]) || $tokens[$i + 1][0] !== T_DOUBLE_COLON
                || !self::is($tokens[$i + 2], 'error') || $tokens[$i + 3] !== '(') {
                continue;
            }
            $args = self::arguments($tokens, $i + 4);
            $status = $args[3] ?? [];
            if (count($status) !== 1 || !is_array($status[0]) || $status[0][0] !== T_LNUMBER || (int) $status[0][1] < 500
                || in_array((int) $status[0][1], [502, 503, 504, 507], true)) {
                continue;
            }
            foreach ($args[2] ?? [] as $t) {
                if (self::is($t, 'getMessage')) {
                    $out[] = $tokens[$i][2];
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<mixed> $tokens
     * @return list<list<mixed>>
     */
    private static function arguments(array $tokens, int $start): array
    {
        $args = [];
        $current = [];
        $depth = 0;
        for ($j = $start, $n = count($tokens); $j < $n; $j++) {
            $t = $tokens[$j];
            $s = is_array($t) ? $t[1] : $t;
            if (in_array($s, ['(', '[', '{', '${'], true) || (is_array($t) && $t[0] === T_CURLY_OPEN)) {
                $depth++;
            } elseif (in_array($s, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    $args[] = $current;
                    break;
                }
                $depth--;
            } elseif ($s === ',' && $depth === 0) {
                $args[] = $current;
                $current = [];
                continue;
            }
            $current[] = $t;
        }

        return $args;
    }

    private static function is(mixed $token, string $text): bool
    {
        return is_array($token) && $token[0] === T_STRING && $token[1] === $text;
    }
}
