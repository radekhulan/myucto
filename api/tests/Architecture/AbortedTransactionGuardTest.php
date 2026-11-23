<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\SourceCorpus;
use PHPUnit\Framework\TestCase;

/**
 * MariaDB po chybě 1020 (Record has changed since last read) nebo 1213 (deadlock) odvolá
 * CELOU transakci, `PDO::inTransaction()` ale dál vrací true. Hrozí dvě chyby:
 *
 *  1. kód chybu spolkne a pokračuje: další zápisy běží v autocommitu a zůstanou
 *     polovičatá data („zombie transakce"), nebo se tichá chyba už nikdy nedožene,
 *  2. savepoint helper zavolá `ROLLBACK TO SAVEPOINT`, který po odvolání neexistuje,
 *     a chybou 1305 přemaskuje původní příčinu.
 *
 * Brána hlídá:
 *  - široký `catch` (Throwable, Exception, RuntimeException, PDOException) uvnitř okna
 *    otevřené transakce (lexikálně mezi begin/START TRANSACTION/SAVEPOINT
 *    a commit/COMMIT/RELEASE/rollBack/ROLLBACK), který
 *    chybu nevyhodí dál a neptá se `TransactionFailure::`,
 *  - `ROLLBACK TO SAVEPOINT` v catch bloku, který může chytit PDOException, bez
 *    předchozího `TransactionFailure::` v témže bloku (v celém `api/src`),
 *  - `ROLLBACK TO SAVEPOINT` mimo catch (helpery volané z catch, finally) bez předchozího
 *    `TransactionFailure::` v metodě; jen v účetním a platebním jádru ({@see self::HELPER_SCOPE}).
 *
 * Hlídá se kód metody, ne její volající: spolknutí v metodě bez vlastní transakce, kterou
 * volá cizí transakce (např. auditní zápis v párování), brána nevidí; to kryjí testy služeb.
 *
 * Skenují se tokeny, ne text: komentář ani řetězec se jménem třídy guard nesplní.
 * Výjimku dostává METODA (soubor::metoda) s důvodem, nikdy celý soubor.
 */
final class AbortedTransactionGuardTest extends TestCase
{
    private const BROAD_CATCH = ['Throwable', 'Exception', 'RuntimeException', 'PDOException'];

    /** Kde se hlídá i `ROLLBACK TO SAVEPOINT` mimo catch blok (prefix cesty pod api/src). */
    private const HELPER_SCOPE = [
        'Action/Accounting/',
        'Action/Bank/',
        'Repository/ClientBankAccountRepository.php',
        'Repository/Payroll/PayrollPaymentMatchRepository.php',
        'Service/Accounting/',
        'Service/Bank/',
        'Service/Invoice/',
        'Service/License/LicenseCapacityGate.php',
        'Service/Payroll/Payment/',
        'Service/Payroll/Run/PayrollRunCommandService.php',
        'Service/Report/',
    ];

    /**
     * Široké catch bloky v okně transakce, které chybu vědomě nevyhazují. Klíč `soubor::metoda`.
     *
     * @var array<string,string>
     */
    private const CATCH_ALLOWLIST = [];

    /**
     * `ROLLBACK TO SAVEPOINT` bez guardu. Klíč `soubor::metoda`.
     *
     * @var array<string,string>
     */
    private const ROLLBACK_ALLOWLIST = [
        'Service/Bank/StatementMatcher.php::rollBack'
            => 'Volá ho jen rollBackIfOpen() až po TransactionFailure::rollbackIfAborted.',
        'Service/Accounting/Bank/BankPostingBackfill.php::finishCandidateTransaction'
            => 'Volající odvolanou transakci vyhodí (TransactionFailure::aborted) dřív, než helper zavolá.',
        'Service/Accounting/Bank/PurchaseRoundingSettlementBackfill.php::run'
            => 'ROLLBACK TO jen po úspěchu nebo PostingException; jiná chyba (i 1020/1213) z metody vyletí bez něj.',
        'Action/Bank/BankPaymentCandidatesAction.php::{closure}'
            => 'Náhled v savepointu: ROLLBACK TO jen v běžném toku, chyba vyletí bez něj.',
    ];

    public function testBroadCatchInOpenTransactionRethrowsOrAsksTransactionFailure(): void
    {
        $offenders = [];
        foreach ($this->methods() as $key => $method) {
            if (isset(self::CATCH_ALLOWLIST[$key])) {
                continue;
            }
            foreach ($method['catches'] as $catch) {
                if ($catch['inWindow'] && $catch['pdoCapable'] && !$catch['throws'] && !$catch['asks']) {
                    $offenders[] = $key . ' (řádek ' . $catch['line'] . ')';
                }
            }
        }

        self::assertSame([], $offenders,
            "Široký catch uvnitř otevřené transakce spolkne i odvolanou transakci (1020/1213).\n"
            . "Vyhoď chybu dál, nebo se zeptej TransactionFailure::aborted()/rollbackIfAborted()\n"
            . "(vzor DimensionPeriodGuard::transaction). Výjimku uděl metodě s důvodem.\n  "
            . implode("\n  ", $offenders));
    }

    public function testRollbackToSavepointIsGuarded(): void
    {
        $offenders = [];
        foreach ($this->methods() as $key => $method) {
            if (isset(self::ROLLBACK_ALLOWLIST[$key])) {
                continue;
            }
            foreach ($method['rollbacks'] as $rollback) {
                $checked = $rollback['inCatch'] ? $rollback['pdoCapable'] : self::inHelperScope($key);
                if ($checked && !$rollback['guarded']) {
                    $offenders[] = $key . ' (řádek ' . $rollback['line'] . ')';
                }
            }
        }

        self::assertSame([], $offenders,
            "ROLLBACK TO SAVEPOINT po odvolané transakci skončí chybou 1305 a přemaskuje původní\n"
            . "příčinu. Jako první krok zavolej TransactionFailure::rollbackIfAborted().\n  "
            . implode("\n  ", $offenders));
    }

    public function testAllowlistsPointToExistingMethods(): void
    {
        $methods = $this->methods();
        $stale = array_values(array_filter(
            array_keys(self::CATCH_ALLOWLIST + self::ROLLBACK_ALLOWLIST),
            static fn (string $key): bool => !isset($methods[$key]),
        ));

        self::assertSame([], $stale, 'Výjimka pro metodu, která už neexistuje, nic nekryje.');
    }

    public function testSqlSnapshotWithoutBeginTransactionIsScanned(): void
    {
        $run = $this->methods()['Infrastructure/Database/ReadOnlySnapshot.php::run'] ?? null;

        self::assertNotNull($run, 'Soubor, který transakci otevírá jen SQL příkazem, musí brána číst.');
    }

    public function testScannerSeesThroughCommentsAndStrings(): void
    {
        $code = <<<'PHP'
            <?php
            final class Probe
            {
                public function swallow(\PDO $pdo): void
                {
                    $pdo->beginTransaction();
                    try {
                        $pdo->exec('UPDATE t SET a = 1');
                    } catch (\Throwable $e) {
                        // TransactionFailure::aborted($e) throw
                        $note = 'TransactionFailure::rollbackIfAborted throw';
                        $pdo->exec('ROLLBACK TO SAVEPOINT x');
                    }
                    $pdo->commit();
                }

                public function guarded(\PDO $pdo, string $name): void
                {
                    $pdo->exec("SAVEPOINT {$name}");
                    try {
                    } catch (Throwable $e) {
                        if (!TransactionFailure::rollbackIfAborted($pdo, $e)) {
                            $pdo->exec("ROLLBACK TO SAVEPOINT {$name}");
                        }
                    }
                }

                public function rethrows(\PDO $pdo): void
                {
                    $pdo->beginTransaction();
                    try {
                    } catch (\RuntimeException $e) {
                        throw $e;
                    }
                }

                public function domainOnly(\PDO $pdo): void
                {
                    $pdo->exec('SAVEPOINT x');
                    try {
                    } catch (\DomainException $e) {
                        $pdo->exec('ROLLBACK TO SAVEPOINT x');
                    }
                }

                public function afterCommit(\PDO $pdo): void
                {
                    $pdo->beginTransaction();
                    $pdo->commit();
                    try {
                    } catch (\Throwable) {
                    }
                }

                public function sqlSnapshot(\PDO $pdo): void
                {
                    $pdo->exec('START TRANSACTION READ ONLY');
                    try {
                    } catch (\Throwable) {
                    }
                    $pdo->exec('COMMIT');
                    try {
                    } catch (\Throwable) {
                    }
                }
            }
            PHP;
        $methods = self::scan($code, 'Probe.php');

        $swallow = $methods['Probe.php::swallow'];
        self::assertTrue($swallow['catches'][0]['inWindow']);
        self::assertFalse($swallow['catches'][0]['asks'], 'Komentář ani řetězec guard nesplní.');
        self::assertFalse($swallow['catches'][0]['throws']);
        self::assertFalse($swallow['rollbacks'][0]['guarded']);
        self::assertTrue($methods['Probe.php::guarded']['catches'][0]['asks']);
        self::assertTrue($methods['Probe.php::guarded']['rollbacks'][0]['guarded'], 'Interpolovaný řetězec se počítá.');
        self::assertSame(1, count($methods['Probe.php::guarded']['rollbacks']));
        self::assertTrue($methods['Probe.php::rethrows']['catches'][0]['throws']);
        self::assertFalse($methods['Probe.php::domainOnly']['rollbacks'][0]['pdoCapable']);
        self::assertFalse($methods['Probe.php::afterCommit']['catches'][0]['inWindow']);
        self::assertTrue($methods['Probe.php::sqlSnapshot']['catches'][0]['inWindow'], 'START TRANSACTION otevírá okno jako beginTransaction.');
        self::assertFalse($methods['Probe.php::sqlSnapshot']['catches'][1]['inWindow'], 'COMMIT jako SQL okno zavírá.');
    }

    private static function inHelperScope(string $key): bool
    {
        foreach (self::HELPER_SCOPE as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,array{catches:list<array<string,mixed>>, rollbacks:list<array<string,mixed>>}> */
    private function methods(): array
    {
        static $methods = null;
        if ($methods !== null) {
            return $methods;
        }
        $methods = [];
        foreach (SourceCorpus::sources(dirname(__DIR__, 2) . '/src') as $rel => $code) {
            if (!str_contains($code, 'beginTransaction') && stripos($code, 'SAVEPOINT') === false
                && stripos($code, 'START TRANSACTION') === false) {
                continue;
            }
            $methods += self::scan($code, str_replace('\\', '/', $rel));
        }

        return $methods;
    }

    /** @return array<string,array{catches:list<array<string,mixed>>, rollbacks:list<array<string,mixed>>}> */
    private static function scan(string $code, string $rel): array
    {
        $tokens = [];
        foreach (token_get_all($code) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                continue;
            }
            $tokens[] = is_array($t) ? $t : [0, $t, 0];
        }
        $count = count($tokens);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] !== T_FUNCTION && $tokens[$i][0] !== T_FN) {
                continue;
            }
            $named = ($tokens[$i + 1][0] ?? null) === T_STRING;
            $open = self::next($tokens, $i, '{', ';');
            if ($open === null || (!$named && self::enclosedByMethod($out, $i))) {
                continue;
            }
            $close = self::matching($tokens, $open);
            $name = $named ? $tokens[$i + 1][1] : '{closure}';
            $out[$rel . '::' . $name] = self::method($tokens, $open, $close) + ['range' => [$open, $close]];
            if ($named) {
                $i = $close;
            }
        }

        return array_map(static fn (array $m): array => array_diff_key($m, ['range' => true]), $out);
    }

    /** @param array<string,array{range:array{0:int,1:int}}> $methods */
    private static function enclosedByMethod(array $methods, int $at): bool
    {
        foreach ($methods as $method) {
            if ($method['range'][0] < $at && $at < $method['range'][1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{0:int,1:string,2:int}> $tokens
     * @return array{catches:list<array<string,mixed>>, rollbacks:list<array<string,mixed>>}
     */
    private static function method(array $tokens, int $open, int $close): array
    {
        $catches = [];
        $depth = 0;
        for ($i = $open; $i < $close; $i++) {
            [$id, $text] = $tokens[$i];
            if (self::isSqlString($tokens[$i])) {
                if (preg_match('/^["\']?\s*SAVEPOINT\b/i', $text) === 1) {
                    $depth++;
                } elseif (preg_match('/^["\']?\s*(RELEASE|ROLLBACK TO)\s+SAVEPOINT\b/i', $text) === 1) {
                    $depth = max(0, $depth - 1);
                } elseif (preg_match('/^["\']?\s*START\s+TRANSACTION\b/i', $text) === 1) {
                    $depth++;
                } elseif (preg_match('/^["\']?\s*(COMMIT|ROLLBACK)\s*["\']?$/i', $text) === 1) {
                    $depth = max(0, $depth - 1);
                }
            } elseif ($id === T_STRING && $text === 'beginTransaction') {
                $depth++;
            } elseif ($id === T_STRING && in_array($text, ['commit', 'rollBack'], true)) {
                $depth = max(0, $depth - 1);
            }
            if ($id !== T_CATCH) {
                continue;
            }
            $paren = self::next($tokens, $i, '(', null);
            $parenClose = self::matching($tokens, $paren, '(', ')');
            $pdoCapable = false;
            for ($j = $paren; $j < $parenClose; $j++) {
                if (in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $type = ltrim((string) strrchr('\\' . $tokens[$j][1], '\\'), '\\');
                    $pdoCapable = $pdoCapable || in_array($type, self::BROAD_CATCH, true);
                }
            }
            $blockOpen = self::next($tokens, $parenClose, '{', null);
            $blockClose = self::matching($tokens, $blockOpen);
            $catches[] = [
                'line' => self::line($tokens, $i),
                'open' => $blockOpen,
                'close' => $blockClose,
                'inWindow' => $depth > 0,
                'pdoCapable' => $pdoCapable,
                'throws' => self::contains($tokens, $blockOpen, $blockClose, static fn (array $t): bool => $t[0] === T_THROW),
                'asks' => self::contains($tokens, $blockOpen, $blockClose, self::asksTransactionFailure(...)),
            ];
        }

        $rollbacks = [];
        for ($i = $open; $i < $close; $i++) {
            if (!self::isSqlString($tokens[$i]) || stripos($tokens[$i][1], 'ROLLBACK TO SAVEPOINT') === false) {
                continue;
            }
            $catch = null;
            foreach ($catches as $candidate) {
                if ($candidate['open'] < $i && $i < $candidate['close'] && ($catch === null || $candidate['open'] > $catch['open'])) {
                    $catch = $candidate;
                }
            }
            $rollbacks[] = [
                'line' => self::line($tokens, $i),
                'inCatch' => $catch !== null,
                'pdoCapable' => $catch['pdoCapable'] ?? true,
                'guarded' => self::contains($tokens, $catch['open'] ?? $open, $i, self::asksTransactionFailure(...)),
            ];
        }

        return [
            'catches' => array_map(
                static fn (array $c): array => array_diff_key($c, ['open' => true, 'close' => true]),
                $catches,
            ),
            'rollbacks' => $rollbacks,
        ];
    }

    /** @param array{0:int,1:string,2:int} $token */
    private static function isSqlString(array $token): bool
    {
        return in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true);
    }

    /** @param array{0:int,1:string,2:int} $token */
    private static function asksTransactionFailure(array $token): bool
    {
        return in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && preg_match('/(?:^|\\\\)TransactionFailure$/', $token[1]) === 1;
    }

    /**
     * @param list<array{0:int,1:string,2:int}> $tokens
     * @param callable(array{0:int,1:string,2:int}):bool $predicate
     */
    private static function contains(array $tokens, int $from, int $to, callable $predicate): bool
    {
        for ($i = $from; $i < $to; $i++) {
            if ($predicate($tokens[$i])) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0:int,1:string,2:int}> $tokens */
    private static function next(array $tokens, int $from, string $wanted, ?string $stop): ?int
    {
        for ($i = $from + 1, $count = count($tokens); $i < $count; $i++) {
            if ($tokens[$i][0] === 0 && $tokens[$i][1] === $wanted) {
                return $i;
            }
            if ($stop !== null && $tokens[$i][0] === 0 && $tokens[$i][1] === $stop) {
                return null;
            }
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}> $tokens */
    private static function matching(array $tokens, int $open, string $left = '{', string $right = '}'): int
    {
        $depth = 0;
        for ($i = $open, $count = count($tokens); $i < $count; $i++) {
            [$id, $text] = $tokens[$i];
            if ($id === 0 && $text === $left || $left === '{' && in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif ($id === 0 && $text === $right) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $count - 1;
    }

    /** @param list<array{0:int,1:string,2:int}> $tokens */
    private static function line(array $tokens, int $at): int
    {
        for ($i = $at; $i >= 0; $i--) {
            if ($tokens[$i][2] > 0) {
                return $tokens[$i][2];
            }
        }

        return 1;
    }
}
