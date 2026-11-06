<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * R4 (SECURITY-REPORT-2026-08) — vlastnictví bankovních dat se NESMÍ odvozovat
 * z čísla účtu. Static source scan, stejný princip jako {@see TenantPredicateTest}:
 * chytí návrat vzoru dřív, než ho někdo zase napíše do produkce.
 *
 * Hlídané vzory:
 *
 *  1. Kde je volající supplier ZNÁMÝ (session), rozhoduje
 *     {@see \MyInvoice\Repository\BankStatementOwnershipResolver}, ne seznam
 *     kandidátů z čísla účtu. `resolveSupplierCandidates()` smí zůstat jen na
 *     opačné úloze (kdo je vlastník transakce z importu).
 *  2. Dotazy, které vracejí bankovní pohyby cizím tenantům do těla odpovědi,
 *     musí mít tenant predikát v SQL, ne až filtr v PHP.
 *  3. `bank_code_norm IN ('', ?)` dělá z prázdného kódu banky wildcard —
 *     v cestě, která hledá VLASTNÍKA napříč firmami, to je cross-tenant match.
 *  4. Zápis do registru vlastních účtů prochází claim guardem.
 */
final class BankOwnershipPredicateGuardTest extends TestCase
{
    /** Zdroják BEZ komentářů — hlídáme kód, ne to, co o něm říká dokumentace. */
    private static function src(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/src/' . $relative;
        self::assertFileExists($path);
        $raw = file_get_contents($path);
        self::assertIsString($raw);

        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $out .= str_repeat("\n", substr_count($token[1], "\n"));
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /** Tělo metody od její signatury po signaturu následující metody. */
    private static function methodBody(string $code, string $method): string
    {
        $start = strpos($code, 'function ' . $method . '(');
        self::assertNotFalse($start, "Metoda {$method}() v souboru nenalezena.");
        $rest = substr($code, $start + 1);
        $next = preg_match('/\n    (?:public|private|protected|abstract|final)[^\n]*function /', $rest, $m, PREG_OFFSET_CAPTURE) === 1
            ? $m[0][1]
            : strlen($rest);

        return substr($rest, 0, $next);
    }

    public function testPostManualUsesOwnershipResolverInsteadOfAccountCandidates(): void
    {
        $body = self::methodBody(self::src('Service/Accounting/Bank/BankPostingService.php'), 'postManual');

        self::assertStringNotContainsString(
            'resolveSupplierCandidates',
            $body,
            'postManual() zná supplier ze session — vlastnictví se nesmí odvozovat z čísla účtu (R4).'
        );
        self::assertStringContainsString(
            'lockTransaction($supplierId, $txId)',
            $body,
            'postManual() musí ověřit vlastnictví přes BankStatementOwnershipResolver.'
        );
    }

    public function testOwnershipHelperUsesSharedResolverPredicate(): void
    {
        $body = self::methodBody(self::src('Service/Accounting/Bank/BankPostingService.php'), 'txOwnedBySupplier');

        self::assertStringContainsString('(new BankStatementOwnershipResolver($this->db))->transactionOwned($txId, $supplierId, $currentRead)', $body);
        $postingLock = self::methodBody(self::src('Service/Accounting/Bank/BankPostingService.php'), 'lockTransaction');
        self::assertStringContainsString('(new BankTransactionContext($this->db))->mutableTransaction($supplierId, $txId)', $postingLock);
        $contextLock = self::methodBody(self::src('Service/Bank/BankTransactionContext.php'), 'lock');
        self::assertStringContainsString('$this->lockContext($supplierId)', $contextLock);
        self::assertStringContainsString('$this->findOwned($supplierId, $txId)', $contextLock);
        $mutableLock = self::methodBody(self::src('Service/Bank/BankTransactionContext.php'), 'mutableTransaction');
        self::assertStringContainsString('assertSourceMutable($supplierId, \'bank\', $txId)', $mutableLock);
        self::assertStringContainsString('$this->findOwned($supplierId, $txId)', $mutableLock);
        $ownedRead = self::methodBody(self::src('Service/Bank/BankTransactionContext.php'), 'findOwned');
        self::assertStringContainsString('transactionOwned($txId, $supplierId, true)', $ownedRead);
        self::assertStringContainsString('$this->find($txId, true)', $ownedRead);
        $resolver = self::methodBody(self::src('Repository/BankStatementOwnershipResolver.php'), 'transactionOwned');
        self::assertStringContainsString('self::sql(\'bs\', $currentRead)', $resolver);
        self::assertStringContainsString('self::params($supplierId)', $resolver);
        self::assertStringContainsString('$currentRead ? \' FOR UPDATE\' : \'\'', $resolver);
    }

    public function testJournalTransferBankCandidatesCarryTenantPredicateInSql(): void
    {
        $body = self::methodBody(
            self::src('Action/Accounting/Closing/JournalTransferAction.php'),
            'findBankCandidates'
        );

        self::assertStringContainsString(
            'BankStatementOwnershipResolver::sql(',
            $body,
            'Kandidáti převodu jdou zpátky v těle 409 — tenant predikát musí být v SQL (R4).'
        );
        self::assertStringContainsString('BankStatementOwnershipResolver::params(', $body);
    }

    public function testSupplierResolutionDoesNotWildcardEmptyBankCode(): void
    {
        $code = self::src('Service/Accounting/Bank/BankPostingService.php');

        self::assertStringNotContainsString(
            "bank_code_norm IN ('', ?)",
            $code,
            "Prázdný bank_code_norm nesmí být wildcard — zrcadli BankStatementOwnershipResolver::bankCodeMatch()."
        );
    }

    public function testRegisterSeenRunsClaimGuard(): void
    {
        $body = self::methodBody(self::src('Repository/SupplierBankAccountRepository.php'), 'registerSeen');

        self::assertStringContainsString(
            'accountClaimedByOtherSupplier',
            $body,
            'registerSeen() zakládá account_canonical — bez claim guardu vzniknou kolizní řádky dvou tenantů (R4).'
        );
        self::assertStringContainsString(
            'verifiedAccount(',
            $body,
            'Kanonizovat se má prověřená hodnota z currencies, ne syrový řetězec z hlavičky výpisu.'
        );
    }
}
