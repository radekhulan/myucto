<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Obligations\OtherItemForecastService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class OtherItemServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private OtherItemService $service;
    private PostingService $posting;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->service = $container->get(OtherItemService::class);
        $this->posting = $container->get(PostingService::class);
        $this->pdo = $this->db->pdo();
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $container->get(AccountingPeriodRepository::class)->create($this->supplierId, 2099, '2099-01-01', '2099-12-31');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testPayablePostsOnceAndReversalKeepsAuditTrail(): void
    {
        $draft = $this->service->create($this->supplierId, $this->input(), null);
        self::assertSame('draft', $draft['status']);
        self::assertNull($draft['journal_entry_id']);
        self::assertNull($draft['document_no']);

        $posted = $this->service->post($this->supplierId, (int) $draft['id'], null);
        self::assertSame('posted', $posted['status']);
        self::assertStringStartsWith('OZ-2099-', $posted['document_no']);
        self::assertGreaterThan(0, (int) $posted['journal_entry_id']);
        self::assertSame(['325:credit:1200.00', '518:debit:1200.00'], $this->lines((int) $posted['journal_entry_id']));
        self::assertCount(0, $this->service->list($this->supplierId + 100000, [], 1, 50)['items']);

        try {
            $this->service->post($this->supplierId, (int) $draft['id'], null);
            self::fail('Druhé zaúčtování musí být odmítnuto.');
        } catch (OtherItemException $e) {
            self::assertSame('not_draft', $e->errorCode);
        }

        $reversed = $this->service->reverse($this->supplierId, (int) $draft['id'], 'Oprava podkladu', null);
        self::assertSame('reversed', $reversed['status']);
        self::assertGreaterThan(0, (int) $reversed['reversal_entry_id']);
        self::assertSame(['325:debit:1200.00', '518:credit:1200.00'], $this->lines((int) $reversed['reversal_entry_id']));
    }

    public function testSplitPostingKeepsOneSettlementAccountAndForecastsOnlyExpenseLines(): void
    {
        $draft = $this->service->create($this->supplierId, $this->input([
            'counter_account_code' => null,
            'posting_lines' => [
                ['account_code' => '518', 'amount' => 700.25],
                ['account_code' => '378', 'amount' => 499.75],
            ],
        ]), null);
        self::assertNull($draft['counter_account_code']);
        self::assertCount(2, $draft['posting_lines']);
        $forecast = (new OtherItemForecastService($this->db))->resultImpact($this->supplierId, '2099-01-01', '2100-01-01');
        self::assertSame(700.25, $forecast[0]['costs']);

        $posted = $this->service->post($this->supplierId, (int) $draft['id'], null);
        self::assertSame(['325:credit:1200.00', '518:debit:700.25', '378:debit:499.75'],
            $this->lines((int) $posted['journal_entry_id']));
    }

    public function testSplitRepostReversesOriginalLinesAndPreservesDocumentNumber(): void
    {
        $draft = $this->service->create($this->supplierId, $this->input([
            'posting_lines' => [
                ['account_code' => '518', 'amount' => 700],
                ['account_code' => '378', 'amount' => 500],
            ],
        ]), null);
        $posted = $this->service->post($this->supplierId, (int) $draft['id'], null);
        $changed = $this->service->repost($this->supplierId, (int) $draft['id'], [
            'entry_date' => '2099-01-02', 'reason' => 'Oprava rozdělení částek',
            'posting_lines' => [
                ['account_code' => '518', 'amount' => 600],
                ['account_code' => '511', 'amount' => 600],
            ],
        ], null);
        self::assertSame($posted['document_no'], $changed['document_no']);
        self::assertSame(['325:credit:1200.00', '518:debit:600.00', '511:debit:600.00'],
            $this->lines((int) $changed['journal_entry_id']));
        self::assertSame(['325:debit:1200.00', '518:credit:700.00', '378:credit:500.00'],
            $this->lines((int) $changed['reversal_entry_id']));
        self::assertSame(2, count($changed['posting_lines']));
        self::assertNull($changed['counter_account_code']);
    }

    public function testSplitPostingRejectsMismatchedCentsAndAdditionalSettlementAccount(): void
    {
        try {
            $this->service->create($this->supplierId, $this->input([
                'posting_lines' => [
                    ['account_code' => '518', 'amount' => 700],
                    ['account_code' => '378', 'amount' => 499.99],
                ],
            ]), null);
            self::fail('Rozdíl jednoho haléře se nesmí zaokrouhlit.');
        } catch (OtherItemException $e) {
            self::assertSame('posting_lines_total', $e->errorCode);
        }
        $draft = $this->service->create($this->supplierId, $this->input([
            'posting_lines' => [['account_code' => '315', 'amount' => 1200]],
        ]), null);
        try {
            $this->service->post($this->supplierId, (int) $draft['id'], null);
            self::fail('Druhý saldokontní účet by vytvořil paralelní závazek.');
        } catch (OtherItemException $e) {
            self::assertSame('same_accounts', $e->errorCode);
        }
    }

    public function testDraftUpdateReplacesSplitLinesAndLegacyItemHasSyntheticLine(): void
    {
        $legacy = $this->service->create($this->supplierId, $this->input(), null);
        self::assertSame([['account_code' => '518', 'amount' => 1200.0]], $legacy['posting_lines']);

        $changed = $this->service->update($this->supplierId, (int) $legacy['id'], $this->input([
            'posting_lines' => [
                ['account_code' => '518', 'amount' => 800],
                ['account_code' => '378', 'amount' => 400],
            ],
        ]), null);
        self::assertNull($changed['counter_account_code']);
        self::assertSame([
            ['account_code' => '518', 'amount' => 800.0],
            ['account_code' => '378', 'amount' => 400.0],
        ], $changed['posting_lines']);
        $oneLine = $this->service->update($this->supplierId, (int) $legacy['id'], $this->input([
            'counter_account_code' => '511',
        ]), null);
        self::assertSame('511', $oneLine['counter_account_code']);
        self::assertSame([['account_code' => '511', 'amount' => 1200.0]], $oneLine['posting_lines']);
    }

    public function testTaxEvidenceConfirmsWithoutJournal(): void
    {
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")
            ->execute([$this->supplierId]);
        $draft = $this->service->create($this->supplierId, $this->input(['counter_account_code' => null]), null);
        $confirmed = $this->service->post($this->supplierId, (int) $draft['id'], null);
        self::assertSame('confirmed', $confirmed['status']);
        self::assertNull($confirmed['journal_entry_id']);
        $cancelled = $this->service->reverse($this->supplierId, (int) $draft['id'], 'Smlouva skončila', null);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertNull($cancelled['reversal_entry_id']);
    }

    public function testForeignCurrencyDraftIsRejectedUntilRevaluationIsSupported(): void
    {
        try {
            $this->service->create($this->supplierId, $this->input([
                'currency' => 'EUR', 'exchange_rate' => 25.0,
            ]), null);
            self::fail('Cizoměnový závazek bez kurzového přecenění nelze pořídit.');
        } catch (OtherItemException $e) {
            self::assertSame('currency_unsupported', $e->errorCode);
        }
    }

    public function testItemAmountMustBePositiveWholeCents(): void
    {
        foreach ([0.001, 100.005] as $amount) {
            try {
                $this->service->create($this->supplierId, $this->input(['amount' => $amount]), null);
                self::fail('Částka se nesmí tiše zaokrouhlit na haléře.');
            } catch (OtherItemException $e) {
                self::assertSame('invalid_amount', $e->errorCode);
            }
        }
    }

    public function testPostingAndRepostingRejectSameAccountOnBothSides(): void
    {
        $invalid = $this->service->create($this->supplierId, $this->input(['counter_account_code' => '325']), null);
        try {
            $this->service->post($this->supplierId, (int) $invalid['id'], null);
            self::fail('Stejný účet na obou stranách by vynuloval saldokonto.');
        } catch (OtherItemException $e) {
            self::assertSame('same_accounts', $e->errorCode);
        }
        self::assertSame('draft', $this->service->get($this->supplierId, (int) $invalid['id'])['status']);

        $valid = $this->service->create($this->supplierId, $this->input(), null);
        $posted = $this->service->post($this->supplierId, (int) $valid['id'], null);
        try {
            $this->service->repost($this->supplierId, (int) $valid['id'], [
                'reason' => 'Kontrola kontace', 'entry_date' => '2099-02-01',
                'account_code' => '325', 'counter_account_code' => '325',
            ], null);
            self::fail('Přeúčtování na shodné účty musí být odmítnuto.');
        } catch (OtherItemException $e) {
            self::assertSame('same_accounts', $e->errorCode);
        }
        self::assertSame($posted['journal_entry_id'], $this->service->get($this->supplierId, (int) $valid['id'])['journal_entry_id']);
    }

    public function testPayrollCannotBeManuallyCreatedAndForeignPartnerIsDenied(): void
    {
        try {
            $this->service->create($this->supplierId, $this->input(['kind' => 'payroll']), null);
            self::fail('Mzdová položka se zadává výhradně v mzdovém modulu.');
        } catch (OtherItemException $e) {
            self::assertSame('invalid_kind', $e->errorCode);
        }
        try {
            $this->service->create($this->supplierId, $this->input(['partner_id' => 999999999]), null);
            self::fail('Cizí partner musí být odmítnut.');
        } catch (OtherItemException $e) {
            self::assertSame('invalid_partner', $e->errorCode);
        }
    }

    public function testPartnerNameIsFilledFromDirectoryWhenOnlyPartnerIdIsSent(): void
    {
        $this->pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, currency_default_id, is_customer, is_vendor)
             SELECT id, 'Syntetická protistrana s.r.o.', street, city, zip, country_id,
                    'partner@example.com', default_currency_id, 1, 1
               FROM supplier WHERE id = ?"
        )->execute([$this->supplierId]);
        $clientId = (int) $this->pdo->lastInsertId();

        $item = $this->service->create($this->supplierId, $this->input(['partner_id' => $clientId]), null);
        self::assertSame($clientId, (int) $item['partner_id']);
        self::assertSame('Syntetická protistrana s.r.o.', $item['partner_name']);

        $named = $this->service->create($this->supplierId,
            $this->input(['partner_id' => $clientId, 'partner_name' => 'Vlastní název']), null);
        self::assertSame('Vlastní název', $named['partner_name']);
    }

    public function testBankAllocationTracksPartialPaymentWithoutDuplicateMatch(): void
    {
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")
            ->execute([$this->supplierId]);
        $draft = $this->service->create($this->supplierId, $this->input(['amount' => 1200]), null);
        $this->service->post($this->supplierId, (int) $draft['id'], null);
        $this->pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, statement_date, currency)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'synteticky-vypis', hash('sha256', uniqid('', true)), '1000000005/0100', '2099-01-20', 'CZK']);
        $statementId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO bank_transactions (statement_id, posted_at, amount, currency)
             VALUES (?, ?, ?, ?)'
        )->execute([$statementId, '2099-01-20', -500, 'CZK']);
        $transactionId = (int) $this->pdo->lastInsertId();
        try {
            $this->service->allocate($this->supplierId, (int) $draft['id'],
                ['bank_transaction_id' => $transactionId, 'amount' => 0.001], null);
            self::fail('Alokace se nesmí tiše zaokrouhlit na nulu.');
        } catch (OtherItemException $e) {
            self::assertSame('invalid_amount', $e->errorCode);
        }
        $part = $this->service->allocate($this->supplierId, (int) $draft['id'],
            ['bank_transaction_id' => $transactionId, 'amount' => 500], null);
        self::assertEqualsWithDelta(700.0, (float) $part['remaining_amount'], 0.001);
        self::assertCount(1, $this->service->allocations($this->supplierId, (int) $draft['id']));
        try {
            $this->pdo->prepare("UPDATE bank_transactions SET match_status = 'manual' WHERE id = ?")
                ->execute([$transactionId]);
            self::fail('Alokovanou platbu nesmí druhá cesta znovu spárovat.');
        } catch (\PDOException $e) {
            self::assertSame('45000', $e->errorInfo[0]);
        }
        try {
            $this->service->allocate($this->supplierId, (int) $draft['id'],
                ['bank_transaction_id' => $transactionId, 'amount' => 1], null);
            self::fail('Platbu nelze použít nad skutečnou částku.');
        } catch (OtherItemException $e) {
            self::assertSame('payment_overallocated', $e->errorCode);
        }
        $allocationId = (int) $this->service->allocations($this->supplierId, (int) $draft['id'])[0]['id'];
        $restored = $this->service->unallocate($this->supplierId, (int) $draft['id'], $allocationId);
        self::assertEqualsWithDelta(1200.0, (float) $restored['remaining_amount'], 0.001);
        $this->service->allocate($this->supplierId, (int) $draft['id'],
            ['bank_transaction_id' => $transactionId, 'amount' => 500], null);
        $this->pdo->prepare('DELETE FROM bank_transactions WHERE id = ?')->execute([$transactionId]);
        self::assertEqualsWithDelta(1200.0,
            (float) $this->service->get($this->supplierId, (int) $draft['id'])['remaining_amount'], 0.001);
    }

    public function testPayableAcceptsOutgoingBankPaymentPostedAsRedStorno(): void
    {
        $draft = $this->service->create($this->supplierId, $this->input(), null);
        $posted = $this->service->post($this->supplierId, (int) $draft['id'], null);

        $this->pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, statement_date, currency)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            'synteticky-red-storno-vypis',
            hash('sha256', uniqid('', true)),
            '1000000005/0100',
            '2099-01-20',
            'CZK',
        ]);
        $statementId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, match_status, counterparty_name, description)
             VALUES (?, ?, ?, ?, "unmatched", "Syntetický dodavatel", "Red-storno úhrada závazku")'
        )->execute([$statementId, '2099-01-20', -500, 'CZK']);
        $transactionId = (int) $this->pdo->lastInsertId();

        $this->posting->postDocument($this->supplierId, 'bank', $transactionId, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 500, 'is_red_storno' => true],
            ['account_code' => '325', 'side' => 'credit', 'amount' => 500, 'is_red_storno' => true],
        ], ['entry_date' => '2099-01-20', 'posted' => true]);

        self::assertContains(
            $transactionId,
            array_column(
                $this->service->paymentCandidates(
                    $this->supplierId,
                    (int) $posted['id'],
                    '',
                    20,
                    true,
                    false,
                ),
                'id',
            ),
            'Red MD 221 / red D 325 je platný odchozí bankovní pohyb proti závazku.',
        );

        $allocated = $this->service->allocate(
            $this->supplierId,
            (int) $posted['id'],
            ['bank_transaction_id' => $transactionId, 'amount' => 500],
            null,
        );
        self::assertEqualsWithDelta(700.0, (float) $allocated['remaining_amount'], 0.001);
        self::assertCount(1, $this->service->allocations($this->supplierId, (int) $posted['id']));
    }

    public function testResultForecastUsesCounterAccountAndIgnoresBalanceSheetItems(): void
    {
        $expense = $this->service->create($this->supplierId, $this->input(), null);
        $this->service->post($this->supplierId, (int) $expense['id'], null);
        $this->service->create($this->supplierId, $this->input([
            'kind' => 'deposit', 'counter_account_code' => '378', 'amount' => 3000,
        ]), null);
        $forecast = new OtherItemForecastService($this->db);
        $rows = $forecast->resultImpact($this->supplierId, '2099-01-01', '2100-01-01');
        self::assertSame([[
            'currency' => 'CZK', 'revenue' => 0.0, 'costs' => 1200.0,
            'profit' => -1200.0, 'revenue_czk' => 0.0, 'costs_czk' => 1200.0,
            'profit_czk' => -1200.0, 'posted' => 1200.0, 'draft' => 0.0,
        ]], $rows);
    }

    public function testRepostReversesOldEntryAndKeepsDocumentIdentity(): void
    {
        $draft = $this->service->create($this->supplierId, $this->input(), null);
        $posted = $this->service->post($this->supplierId, (int) $draft['id'], null);
        $changed = $this->service->repost($this->supplierId, (int) $posted['id'], [
            'counter_account_code' => '511', 'entry_date' => '2099-01-02',
            'reason' => 'Oprava nákladového účtu',
        ], null);
        self::assertSame($posted['document_no'], $changed['document_no']);
        self::assertSame('posted', $changed['status']);
        self::assertNotSame($posted['journal_entry_id'], $changed['journal_entry_id']);
        self::assertSame(['325:credit:1200.00', '511:debit:1200.00'], $this->lines((int) $changed['journal_entry_id']));
        self::assertSame(['325:debit:1200.00', '518:credit:1200.00'], $this->lines((int) $changed['reversal_entry_id']));
    }

    public function testResultImpactFollowsJournalDatesAcrossReposting(): void
    {
        $this->containerPeriod(2100);
        $draft = $this->service->create($this->supplierId, $this->input(), null);
        $this->service->post($this->supplierId, (int) $draft['id'], null);
        $forecast = new OtherItemForecastService($this->db);
        self::assertSame(1200.0, $forecast->resultImpact($this->supplierId, '2099-01-01', '2100-01-01')[0]['costs']);

        $this->service->repost($this->supplierId, (int) $draft['id'], [
            'counter_account_code' => '511', 'entry_date' => '2100-01-02',
            'reason' => 'Oprava účtu v dalším období',
        ], null);
        self::assertSame(1200.0, $forecast->resultImpact($this->supplierId, '2099-01-01', '2100-01-01')[0]['costs']);
        self::assertSame([], $forecast->resultImpact($this->supplierId, '2100-01-01', '2101-01-01'));
    }

    public function testResultImpactStartsWithOtherItemJournalEntriesAndLooksUpReversalsByIndex(): void
    {
        $draft = $this->service->create($this->supplierId, $this->input(), null);
        $this->service->post($this->supplierId, (int) $draft['id'], null);

        $postedSql = null;
        $statementClass = $this->pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [OtherItemForecastStatement::class, [
            static function (string $sql) use (&$postedSql): void {
                if (str_contains($sql, 'FROM journal_entries entry')) {
                    $postedSql = $sql;
                }
            },
        ]]);
        try {
            $rows = (new OtherItemForecastService($this->db))->resultImpact($this->supplierId, '2099-01-01', '2100-01-01');
        } finally {
            $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $statementClass);
        }

        self::assertSame(1200.0, $rows[0]['costs']);
        self::assertNotNull($postedSql);
        $explain = $this->pdo->prepare('EXPLAIN ' . $postedSql);
        $explain->execute([$this->supplierId, '2099-01-01', '2100-01-01']);
        $plan = $explain->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame('entry', $plan[0]['table']);
        $byTable = array_column($plan, null, 'table');
        self::assertSame('idx_je_supplier_reversed_by', $byTable['original']['key']);
        self::assertSame('ref', $byTable['original']['type']);
    }

    private function containerPeriod(int $year): void
    {
        (new AccountingPeriodRepository($this->db))->create($this->supplierId, $year,
            $year . '-01-01', $year . '-12-31');
    }

    private function input(array $changes = []): array
    {
        return array_replace([
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Syntetické nájemné',
            'issued_on' => '2099-01-01', 'accounting_on' => '2099-01-01',
            'due_on' => '2099-01-20', 'currency' => 'CZK', 'amount' => 1200,
            'counter_account_code' => '518',
        ], $changes);
    }

    private function lines(int $entryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT CONCAT(a.account_code, ":", l.side, ":", CAST(l.amount AS CHAR))
               FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? ORDER BY l.id'
        );
        $stmt->execute([$entryId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}


final class OtherItemForecastStatement extends \PDOStatement
{
    protected function __construct(private readonly \Closure $capture) {}

    public function execute(?array $params = null): bool
    {
        ($this->capture)($this->queryString);

        return parent::execute($params);
    }
}
