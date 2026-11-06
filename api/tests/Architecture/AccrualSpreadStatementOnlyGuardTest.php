<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\SourceCorpus;
use PHPUnit\Framework\TestCase;

/**
 * Průběžné časové rozlišení mimo deník je jen pohled výkazu v Účetnictví → Výkazy
 * a manažerského Měsíčního přehledu. Podání (EPO/XML závěrky, DPPO), uzávěrka, balíček
 * závěrky, příloha, kontroly ani převody ho používat nesmí: počítají s deníkem tak, jak je.
 *
 * Brána hledá v `api/src` volání `->balanceSheet()`, `->incomeStatement()`
 * a `->incomeStatementByFunction()` s víc než pěti argumenty (šestý je volba
 * `accrualSpread`) nebo s pojmenovaným argumentem `accrualSpread:`. Výjimku mají jen
 * metody uvedené v ALLOWED. Skenují se tokeny, ne text.
 */
final class AccrualSpreadStatementOnlyGuardTest extends TestCase
{
    private const METHODS = ['balanceSheet', 'incomeStatement', 'incomeStatementByFunction'];

    /** soubor (pod api/src) => metoda, která volbu smí předat, s důvodem. */
    private const ALLOWED = [
        // Převádí parametr `accrual_spread` z dotazu výkazu (obrazovka, PDF, XLSX).
        'Action/Accounting/Reports/FinancialStatementAction.php' => 'buildStatement',
        // Manažerský přehled klientovi, volbu dostává z dotazu nebo těla MonthlyReportAction.
        'Service/Report/MonthlyReportService.php' => 'build',
    ];

    public function testOnlyStatementActionPassesAccrualSpread(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $violations = [];
        foreach (SourceCorpus::sources($root) as $relative => $source) {
            foreach (self::violations($source) as [$method, $line]) {
                if ((self::ALLOWED[$relative] ?? null) === $method) {
                    continue;
                }
                $violations[] = $relative . ':' . $line . ' (' . $method . ')';
            }
        }
        self::assertSame([], $violations, 'Průběžné časové rozlišení smí do výkazu předat jen metody z ALLOWED.');
    }

    public function testGuardDetectsPositionalAndNamedArgument(): void
    {
        $source = <<<'PHP'
            <?php
            final class X {
                public function a(): void { $this->statements->incomeStatement(1, 2, null, 'full', null, true); }
                public function b(): void { $this->statements->balanceSheet(1, 2, null, 'full', accrualSpread: true); }
                public function c(): void { $this->statements->balanceSheet(1, 2, f(3, 4), 'full', null,); }
            }
            PHP;
        self::assertSame([['a', 3], ['b', 4]], self::violations($source));
    }

    /**
     * @return list<array{0:string,1:int}> metoda, ve které volání je, a řádek
     */
    private static function violations(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $out = [];
        $function = '';
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_FUNCTION) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $function = $tokens[$j][1];
                        break;
                    }
                    if ($tokens[$j] === '(') {
                        break;
                    }
                }
                continue;
            }
            if (!is_array($token) || !in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }
            $name = $tokens[$i + 1] ?? null;
            if (!is_array($name) || $name[0] !== T_STRING || !in_array($name[1], self::METHODS, true)) {
                continue;
            }
            $open = $i + 2;
            while ($open < $count && is_array($tokens[$open]) && $tokens[$open][0] === T_WHITESPACE) {
                $open++;
            }
            if (($tokens[$open] ?? null) !== '(') {
                continue;
            }
            $depth = 0;
            $args = 0;
            $named = false;
            $hasContent = false;
            $trailingComma = false;
            for ($j = $open; $j < $count; $j++) {
                $t = $tokens[$j];
                if ($t === '(' || $t === '[' || $t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                    if ($depth === 1) {
                        continue;
                    }
                } elseif ($t === ')' || $t === ']' || $t === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($t === ',' && $depth === 1) {
                    $args++;
                    $trailingComma = true;
                    continue;
                }
                if (!(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                    $trailingComma = false;
                }
                if ($depth === 1 && is_array($t) && $t[0] === T_STRING && $t[1] === 'accrualSpread'
                    && (($tokens[$j + 1] ?? null) === ':')) {
                    $named = true;
                }
                if (!(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                    $hasContent = true;
                }
            }
            $argCount = $hasContent ? $args + ($trailingComma ? 0 : 1) : 0;
            if ($named || $argCount > 5) {
                $out[] = [$function, (int) $name[2]];
            }
        }
        return $out;
    }
}
