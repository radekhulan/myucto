<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Bank\StatementImporter;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Určení měny GPC výpisu při importu (#109 follow-up, reálný Fio EUR výpis):
 *
 * Fio dle své specifikace GPC plní pole měny v 075 záznamu KONSTANTNĚ "0203"
 * (CZK) — i u EUR účtu. Dřívější pořadí detekce (per-tx currency → lookup účtu)
 * proto u Fio EUR výpisu „uspělo" s CZK: výpis se zobrazil v Kč a currency guard
 * v matcheru zahodil všechny EUR faktury. Měna REGISTROVANÉHO účtu (GPC výpis je
 * vždy z jednoho účtu = jedna měna) je nově autoritativní; per-tx kód zůstává
 * fallback pro neregistrované účty (CREDITAS/KB ho plní reálně).
 *
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class StatementImporterCurrencyTest extends TestCase
{
    private Connection $db;
    private StatementImporter $importer;
    private BankStatementAction $action;
    private int $supplierId = 0;
    private int $clonedSupplierId = 0;

    /** @var int[] */
    private array $currencyIds = [];
    /** @var int[] */
    private array $statementIds = [];
    /** @var string[] */
    private array $syntheticAccounts = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection (CI runner skipne).');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->importer = $container->get(StatementImporter::class);
            $this->action = $container->get(BankStatementAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $this->supplierId = (int) ($this->db->pdo()->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }
        $this->cleanupSyntheticStatements();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->statementIds as $id) {
            $pdo->prepare('DELETE FROM bank_transaction_imports WHERE original_statement_id = ? OR statement_id = ?')->execute([$id, $id]);
            $pdo->prepare('DELETE FROM bank_transactions WHERE statement_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$id]);
        }
        foreach (array_unique($this->syntheticAccounts) as $account) {
            $pdo->prepare(
                'DELETE FROM supplier_bank_accounts WHERE account_number = ? OR account_canonical = ?'
            )->execute([$account, ltrim($account, '0')]);
        }
        foreach ($this->currencyIds as $id) {
            $pdo->prepare('DELETE FROM currencies WHERE id = ?')->execute([$id]);
        }
        if ($this->clonedSupplierId > 0) {
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->clonedSupplierId]);
        }
        $this->db->close();
    }

    public function testFioEurStatementUsesRegisteredAccountCurrencyDespiteCzkTxField(): void
    {
        // EUR účet registrovaný domácím číslem; Fio v 075 hlásí "00203" (CZK).
        $account = '9990562236';
        $this->registerCurrency('EUR', accountNumber: $account, bankCode: '2010');

        $r = $this->import($this->gpc($account, txCurrency: '00203'));

        $this->assertStatementCurrency($r['statement_id'], 'EUR',
            'měna registrovaného účtu musí přebít Fio konstantu 0203');
        $this->assertTransactionCurrencies($r['statement_id'], 'EUR',
            'per-tx CZK z Fio výpisu by rozbilo currency guard v matcheru');
        $this->assertStatementSupplier($r['statement_id'], $this->supplierId);
    }

    public function testCreditasApiHeaderCanBeStoredInRealSchema(): void
    {
        $currencyId = $this->registerCurrency('CZK', '1000000005', '2250');
        $this->db->pdo()->prepare('UPDATE currencies SET is_active = 1 WHERE id = ?')->execute([$currencyId]);
        $parsed = (new \MyInvoice\Service\Bank\CreditasTransactionParser())->parse([], '1000000005', '2026-09-07');
        $result = $this->importer->importConnectedParsed($parsed, 'synthetic-creditas-' . bin2hex(random_bytes(8)), 'synthetic.json', null, $currencyId, $this->supplierId);
        $this->statementIds[] = $result['statement_id'];
        $query = $this->db->pdo()->prepare('SELECT statement_number, source FROM bank_statements WHERE id = ? AND supplier_id = ?');
        $query->execute([$result['statement_id'], $this->supplierId]);
        self::assertSame(['statement_number' => $parsed['header']['statement_number'], 'source' => 'bank_api'], $query->fetch(PDO::FETCH_ASSOC));
    }

    public function testFioEurStatementMatchesAccountRegisteredByIbanOnly(): void
    {
        // EUR účet evidovaný JEN IBANem (typické pro cizoměnové účty — #109).
        $account = '9990562237';
        $this->registerCurrency('EUR', iban: 'CZ65 2010 0000 0099 9056 2237');

        $r = $this->import($this->gpc($account, txCurrency: '00203'));

        $this->assertStatementCurrency($r['statement_id'], 'EUR',
            'lookup musí najít účet i přes domácí část IBANu');
        $this->assertTransactionCurrencies($r['statement_id'], 'EUR');
    }

    public function testUnregisteredAccountFallsBackToPerTxCurrency(): void
    {
        // Neregistrovaný účet + banka plnící reálný kód (CREDITAS 00978) —
        // původní fallback chování musí zůstat (EUR výpis nesmí spadnout na NULL/CZK).
        $account = '9990562238';

        $r = $this->import($this->gpc($account, txCurrency: '00978'));

        $this->assertStatementCurrency($r['statement_id'], 'EUR',
            'bez registrace účtu rozhoduje per-tx kód (Creditas case)');
        $this->assertTransactionCurrencies($r['statement_id'], 'EUR');
    }

    public function testSharedAccountNumberUsesExplicitCurrencyId(): void
    {
        // #167: víceměnový účet (Raiffeisenbank) — CZK i EUR sdílí JEDNO číslo účtu.
        // GPC měnu nenese; bez explicitní volby by lookup vrátil první variantu (CZK).
        // Předaný currencyId (EUR) musí být autoritativní.
        $account = '9990562239';
        $this->registerCurrency('CZK', accountNumber: $account, bankCode: '5500'); // založen první → default lookup
        $eurId = $this->registerCurrency('EUR', accountNumber: $account, bankCode: '5500');

        // Bez volby: dnešní (nejednoznačné) chování — vezme se první shoda = CZK.
        $rAuto = $this->import($this->gpc($account, txCurrency: '00203', stmtNo: '003'));
        $this->assertStatementCurrency($rAuto['statement_id'], 'CZK',
            'bez currencyId vrátí lookup první variantu (CZK)');

        // S volbou EUR: měna zvoleného účtu přebíjí pořadí v DB (jiné č. výpisu → jiný file_hash).
        $rEur = $this->import($this->gpc($account, txCurrency: '00203', stmtNo: '004'), currencyId: $eurId);
        $this->assertStatementCurrency($rEur['statement_id'], 'EUR',
            'currencyId EUR musí přebít sdílené číslo účtu (#167)');
        $this->assertTransactionCurrencies($rEur['statement_id'], 'EUR');
    }

    public function testOverlappingStatementsDoNotDuplicateTransactions(): void
    {
        $account = '9990562240';
        $currencyId = $this->registerCurrency('CZK', accountNumber: $account, bankCode: '0100');

        $first = $this->import($this->gpc($account, txCurrency: '00203', stmtNo: '005'), $currencyId);
        $second = $this->import($this->gpc($account, txCurrency: '00203', stmtNo: '006'), $currencyId);

        self::assertSame(2, $first['transactions']);
        self::assertSame(0, $second['transactions'], 'Stejné pohyby z překrývajícího se výpisu se podruhé nevloží.');
        self::assertSame(0, (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM bank_transactions WHERE statement_id = ' . (int) $second['statement_id']
        )->fetchColumn());
    }

    public function testAccountSharedByMultipleSuppliersDoesNotGuessStatementOwner(): void
    {
        $otherSupplierId = $this->cloneSupplier();

        $account = '9990562241';
        $this->registerCurrency('EUR', accountNumber: $account, bankCode: '2010');
        $this->registerCurrency('EUR', accountNumber: $account, bankCode: '2010', supplierId: $otherSupplierId);

        $r = $this->import($this->gpc($account, txCurrency: '00978', stmtNo: '007'));

        $this->assertStatementCurrency($r['statement_id'], 'EUR');
        $this->assertStatementSupplier($r['statement_id'], null,
            'Sdílené číslo účtu mezi tenanty nesmí být automaticky přiřazeno první firmě.');
    }

    public function testCrossRegistryConflictDoesNotGuessStatementOwner(): void
    {
        $otherSupplierId = $this->cloneSupplier();
        $account = '9990562242';
        $this->registerCurrency('EUR', accountNumber: $account, bankCode: '2010');
        $this->registerBankAccount($otherSupplierId, $account, '2010', 'EUR');

        $r = $this->import($this->gpc($account, txCurrency: '00978', stmtNo: '008'));

        $this->assertStatementCurrency($r['statement_id'], 'EUR');
        $this->assertStatementSupplier($r['statement_id'], null,
            'Konflikt currencies vs supplier_bank_accounts musí zůstat bez tenant scope.');
    }

    public function testAmbiguousBankAccountRegistryDoesNotGuessStatementOwner(): void
    {
        $otherSupplierId = $this->cloneSupplier();
        $account = '9990562243';
        $this->registerBankAccount($this->supplierId, $account, '0100', 'CZK');
        $this->registerBankAccount($otherSupplierId, $account, '0100', 'CZK');

        $r = $this->import($this->gpc($account, txCurrency: '00203', stmtNo: '009'));

        $this->assertStatementSupplier($r['statement_id'], null,
            'Dva vlastníci v registru vlastních účtů nesmí vybrat prvního.');
    }

    public function testUniqueBankAccountRegistryAssignsStatementOwner(): void
    {
        $otherSupplierId = $this->cloneSupplier();
        $account = '9990562244';
        $this->registerBankAccount($otherSupplierId, $account, '0100', 'CZK');

        $r = $this->import($this->gpc($account, txCurrency: '00203', stmtNo: '010'));

        $this->assertStatementSupplier($r['statement_id'], $otherSupplierId);
    }

    public function testExplicitCurrencyIdRemainsAuthoritativeAcrossRegistryConflict(): void
    {
        $otherSupplierId = $this->cloneSupplier();
        $account = '9990562245';
        $currencyId = $this->registerCurrency('EUR', accountNumber: $account, bankCode: '2010');
        $this->registerBankAccount($otherSupplierId, $account, '2010', 'EUR');

        $r = $this->import(
            $this->gpc($account, txCurrency: '00203', stmtNo: '011'),
            currencyId: $currencyId,
        );

        $this->assertStatementCurrency($r['statement_id'], 'EUR');
        $this->assertStatementSupplier($r['statement_id'], $this->supplierId,
            'Autorizovaná explicitní volba currencies.id musí mít přednost před automatickou heuristikou.');
    }

    public function testSameNumberAtDifferentBanksRequiresExplicitAccountChoice(): void
    {
        $account = '9990562299';
        $this->registerCurrency('CZK', accountNumber: $account, bankCode: '5500');
        $fioId = $this->registerCurrency('CZK', accountNumber: $account, bankCode: '2010');

        $resolved = $this->resolveTargetCurrency($account);
        self::assertNotNull($resolved['error']);
        $response = ($resolved['error'])(new Response());
        self::assertSame(409, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true) ?: [];
        $candidates = $body['error']['candidates'] ?? [];
        self::assertCount(2, $candidates);
        self::assertEqualsCanonicalizing(['2010', '5500'], array_column($candidates, 'bank_code'));
        self::assertStringContainsString('/2010', implode(' ', array_column($candidates, 'label')));
        self::assertStringContainsString('/5500', implode(' ', array_column($candidates, 'label')));

        $explicit = $this->resolveTargetCurrency($account, $fioId);
        self::assertNull($explicit['error']);
        self::assertSame($fioId, $explicit['currency_id']);

        $imported = $this->import(
            $this->gpc($account, txCurrency: '00203', stmtNo: '012'),
            currencyId: $fioId,
        );
        $this->db->pdo()->prepare(
            'INSERT INTO bank_statements
                (source, file_name, file_hash, account_number, bank_code, currency,
                 statement_date, curr_balance)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'pdf', 'TEST-212-filter-rb.pdf', hash('sha256', 'test-212-filter-rb'),
            $account, '5500', 'CZK', '2026-06-30', 2000.00,
        ]);
        $rbStatementId = (int) $this->db->pdo()->lastInsertId();
        $this->statementIds[] = $rbStatementId;

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            fn (string $name, mixed $default = null): mixed => $name === SupplierScopeMiddleware::ATTR_CURRENT_ID
                ? $this->supplierId
                : $default,
        );
        $request->method('getQueryParams')->willReturn([
            'filter' => ['account' => $account, 'bank_code' => '2010'],
        ]);
        $listResponse = $this->action->list($request, new Response());
        $list = json_decode((string) $listResponse->getBody(), true) ?: [];
        $row = array_values(array_filter(
            $list['items'] ?? [],
            static fn (array $item): bool => (int) ($item['id'] ?? 0) === $imported['statement_id'],
        ))[0] ?? null;
        self::assertIsArray($row);
        self::assertSame('2010', $row['bank_code'] ?? null);
        self::assertStringContainsString('2010', (string) ($row['account_label'] ?? ''));
        self::assertNotContains(
            $rbStatementId,
            array_map(static fn (array $item): int => (int) ($item['id'] ?? 0), $list['items'] ?? []),
        );
    }

    public function testAccountBalancesSeparateBanksAndIncludePdfStatements(): void
    {
        $account = '9990562300';
        $fioId = $this->registerCurrency('CZK', accountNumber: $account, bankCode: '2010');
        $rbId = $this->registerCurrency('CZK', accountNumber: $account, bankCode: '5500');

        $this->insertStatement('gpc', $account, '2010', '2026-06-30', 1000.00, 'fio');
        $this->insertStatement('pdf', $account, '5500', '2026-06-30', 2000.00, 'rb');
        $this->insertStatement('gpc', $account, null, '2026-07-31', 9999.00, 'legacy-ambiguous');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            fn (string $name, mixed $default = null): mixed => $name === SupplierScopeMiddleware::ATTR_CURRENT_ID
                ? $this->supplierId
                : $default,
        );
        $response = $this->action->accountBalances($request, new Response());
        $body = json_decode((string) $response->getBody(), true) ?: [];

        self::assertSame(200, $response->getStatusCode());
        $accounts = [];
        foreach ($body['accounts'] ?? [] as $item) {
            $accounts[(int) $item['id']] = $item;
        }
        self::assertSame(1000.0, (float) $accounts[$fioId]['current_balance']);
        self::assertSame('gpc', (string) $accounts[$fioId]['current_source']);
        self::assertSame(2000.0, (float) $accounts[$rbId]['current_balance']);
        self::assertSame('pdf', (string) $accounts[$rbId]['current_source']);

        $series = [];
        foreach ($body['total_czk']['series'] ?? [] as $item) {
            $series[(int) $item['account_id']] = $item;
        }
        self::assertArrayHasKey($fioId, $series);
        self::assertArrayHasKey($rbId, $series);
        self::assertSame(1000.0, (float) (array_column($series[$fioId]['months'], 'balance_czk', 'month')['2026-06'] ?? 0));
        self::assertSame(2000.0, (float) (array_column($series[$rbId]['months'], 'balance_czk', 'month')['2026-06'] ?? 0));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function insertStatement(
        string $source,
        string $accountNumber,
        ?string $bankCode,
        string $date,
        float $balance,
        string $hashSuffix,
    ): int {
        $this->db->pdo()->prepare(
            'INSERT INTO bank_statements
                (source, file_name, file_hash, account_number, bank_code, currency,
                 statement_date, curr_balance)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $source,
            "TEST-210-{$hashSuffix}.{$source}",
            hash('sha256', "test-210-balances:{$hashSuffix}"),
            $accountNumber,
            $bankCode,
            'CZK',
            $date,
            $balance,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->statementIds[] = $id;
        return $id;
    }

    /** @return array{currency_id:?int,error:mixed} */
    private function resolveTargetCurrency(string $account, ?int $accountId = null): array
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            fn (string $name, mixed $default = null): mixed => $name === SupplierScopeMiddleware::ATTR_CURRENT_ID
                ? $this->supplierId
                : $default,
        );
        $request->method('getParsedBody')->willReturn($accountId === null ? [] : ['account_id' => $accountId]);

        $method = new \ReflectionMethod(BankStatementAction::class, 'resolveTargetCurrency');
        /** @var array{currency_id:?int,error:mixed} $result */
        $result = $method->invoke($this->action, $request, $account);
        return $result;
    }

    private function registerCurrency(
        string $code,
        ?string $accountNumber = null,
        ?string $bankCode = null,
        ?string $iban = null,
        ?int $supplierId = null,
    ): int {
        $this->db->pdo()->prepare(
            'INSERT INTO currencies
                (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                 account_number, bank_code, iban)
             VALUES (?, ?, ?, ?, ?, ?, 2, 0, 0, ?, ?, ?)'
        )->execute([
            $supplierId ?? $this->supplierId,
            $code,
            "TEST {$code} #109" . ($bankCode !== null ? "/{$bankCode}" : ''),
            $code,
            $code,
            $code,
            $accountNumber, $bankCode, $iban !== null ? str_replace(' ', '', $iban) : null,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->currencyIds[] = $id;
        return $id;
    }

    private function registerBankAccount(int $supplierId, string $account, string $bankCode, string $currency): void
    {
        $this->syntheticAccounts[] = $account;
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_bank_accounts
                (supplier_id, label, account_number, bank_code, bank_code_norm, currency,
                 account_canonical, kind, source, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, "current", "manual", 1)'
        )->execute([
            $supplierId,
            'TEST statement owner',
            $account,
            $bankCode,
            $bankCode,
            $currency,
            ltrim($account, '0'),
        ]);
    }

    private function cloneSupplier(): int
    {
        if ($this->clonedSupplierId > 0) {
            return $this->clonedSupplierId;
        }
        $clone = $this->db->pdo()->prepare(
            "INSERT INTO supplier
                (company_name,display_name,street,city,zip,country_id,is_vat_payer,email,
                 default_currency_id,default_vat_rate_id,default_payment_due_days,default_hourly_rate,accounting_mode)
             SELECT '__TEST STATEMENT TENANT B','__TEST STATEMENT TENANT B',street,city,zip,country_id,0,email,
                    default_currency_id,default_vat_rate_id,default_payment_due_days,default_hourly_rate,accounting_mode
               FROM supplier WHERE id=?"
        );
        $clone->execute([$this->supplierId]);
        $this->clonedSupplierId = (int) $this->db->pdo()->lastInsertId();
        self::assertGreaterThan(0, $this->clonedSupplierId);
        return $this->clonedSupplierId;
    }

    private function cleanupSyntheticStatements(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec("DELETE bti FROM bank_transaction_imports bti JOIN bank_statements bs ON bs.id = bti.original_statement_id WHERE bs.file_name = 'TEST-109.gpc'");
        $pdo->prepare(
            "DELETE bt FROM bank_transactions bt
              JOIN bank_statements bs ON bs.id = bt.statement_id
             WHERE bs.file_name = 'TEST-109.gpc'"
        )->execute();
        $pdo->prepare(
            "DELETE FROM bank_statements
             WHERE file_name = 'TEST-109.gpc'"
        )->execute();
    }

    /** @return array{statement_id:int, transactions:int} */
    private function import(string $content, ?int $currencyId = null): array
    {
        $r = $this->importer->import($content, 'TEST-109.gpc', null, $currencyId);
        $this->assertFalse($r['duplicate'], 'testovací GPC nesmí být dedupnuté');
        $this->statementIds[] = $r['statement_id'];
        return $r;
    }

    private function assertStatementCurrency(int $statementId, string $expected, string $message = ''): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT currency FROM bank_statements WHERE id = ?');
        $stmt->execute([$statementId]);
        $this->assertSame($expected, (string) $stmt->fetchColumn(), $message);
    }

    private function assertTransactionCurrencies(int $statementId, string $expected, string $message = ''): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT DISTINCT currency FROM bank_transactions WHERE statement_id = ?');
        $stmt->execute([$statementId]);
        $this->assertSame([$expected], $stmt->fetchAll(PDO::FETCH_COLUMN), $message);
    }

    private function assertStatementSupplier(int $statementId, ?int $expected, string $message = ''): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT supplier_id FROM bank_statements WHERE id = ?');
        $stmt->execute([$statementId]);
        $actual = $stmt->fetchColumn();
        $this->assertSame($expected, $actual === null ? null : (int) $actual, $message);
    }

    /**
     * Minimální validní GPC (074 header + 2× 075 transakce) se zadaným per-tx
     * kódem měny — layout přesně dle GpcParser (fixed-width, viz reálný Fio výpis).
     */
    private function gpc(string $account, string $txCurrency, string $stmtNo = '003'): string
    {
        $this->syntheticAccounts[] = $account;
        $acc16 = str_pad($account, 16, '0', STR_PAD_LEFT);
        $header = '074' . $acc16
            . str_pad('TEST UCET 109', 20)                    // account name (20)
            . '010326'                                         // old balance date
            . str_pad('1337', 14, '0', STR_PAD_LEFT) . '+'     // old balance
            . str_pad('133700', 14, '0', STR_PAD_LEFT) . '+'   // new balance
            . str_pad('0', 14, '0', STR_PAD_LEFT) . '+'        // debit total
            . str_pad('132363', 14, '0', STR_PAD_LEFT) . '+'   // credit total
            . str_pad($stmtNo, 3, '0', STR_PAD_LEFT)           // statement number (salt pro odlišení file_hash)
            . '310326'                                         // statement date
            . 'FIO';

        $tx = fn (string $doc, string $amountCents, string $code, string $name) => '075' . $acc16
            . str_pad('', 16, '0')                             // counterparty account
            . str_pad($doc, 13, '0', STR_PAD_LEFT)             // doc number
            . str_pad($amountCents, 12, '0', STR_PAD_LEFT)     // amount (haléře/centy)
            . $code                                            // 1=debit, 2=credit
            . str_pad('', 10, '0')                             // VS
            . '00'                                             // filler
            . '0000'                                           // counterparty bank code
            . '0000'                                           // KS
            . str_pad('', 10, '0')                             // SS
            . '120326'                                         // value date
            . str_pad($name, 20)                               // client name (20)
            . $txCurrency                                      // currency (5) — testovaný vstup
            . '120326';                                        // posting date

        return $header . "\r\n"
            . $tx('10000000001', '92518', '2', 'PRICHOZI TEST EUR') . "\r\n"
            . $tx('10000000002', '39845', '1', 'Platba prevodem') . "\r\n";
    }
}
