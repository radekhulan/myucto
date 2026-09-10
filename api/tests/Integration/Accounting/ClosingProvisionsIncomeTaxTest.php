<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy Fáze D (audit 2026-07): D9 opravné položky k pohledávkám
 * (§8a/§8c ZoR), D10 rozdělení výsledku hospodaření (431 → 428/364 + srážková daň),
 * D11 splatná daň z příjmů (591/341). Izolovaný supplier v transakci s rollbackem
 * (vzor ClosingWorkflowTest), soft-skip bez cfg.php.
 */
#[Group('integration')]
final class ClosingProvisionsIncomeTaxTest extends TestCase
{
    private const YEAR = 2097;
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private PostingService $posting;
    private ClosingService $closing;
    private AccountingPeriodRepository $periods;
    private JournalEntryRepository $journal;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $currencyId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db      = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->closing = $container->get(ClosingService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->journal = $container->get(JournalEntryRepository::class);
            $seeder        = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId        = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $this->currencyId === 0 || $vatRateId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?)'
        );
        $stmt->execute(['D9-D11 test s.r.o.', $this->czId, 'd9-d11@example.com', $this->currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::ENDS_ON);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    // ── D9: opravné položky k pohledávkám ──────────────────────────────────────

    public function testProvisionsCarryStateAcrossClosedYearAndBookOnlyMovements(): void
    {
        $invoice = $this->receivable(50000, self::YEAR . '-02-01', '2096-04-30');
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $first = $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $invoice, 'legal_amount' => 10000, 'acct_amount' => 5000, 'legal_section' => '8a'],
        ], $this->rv(), $this->meta());
        $firstEntry = $first['entries'][0]['entry_id'];
        $firstLines = $this->entryLines($firstEntry);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR + 1, '2098-01-01', '2098-12-31');
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $unchanged = $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $invoice, 'legal_amount' => 10000, 'acct_amount' => 5000, 'legal_section' => '8a'],
        ], $this->rv(), $this->meta());
        self::assertNull($unchanged['entries'][0]['entry_id']);
        self::assertSame($firstLines, $this->entryLines($firstEntry));
        $next = $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $invoice, 'legal_amount' => 20000, 'acct_amount' => 2000, 'legal_section' => '8a'],
        ], $this->rv(), $this->meta());
        self::assertNotSame($firstEntry, $next['entries'][0]['entry_id']);
        $lines = $this->entryLines($next['entries'][0]['entry_id']);
        self::assertSame(10000.0, $this->sideAmount($lines, '558', 'debit'));
        self::assertSame(3000.0, $this->sideAmount($lines, '559P', 'credit'));
        self::assertSame(7000.0, $this->sideAmount($lines, '391', 'credit'));
        $entry = $this->journal->find($next['entries'][0]['entry_id'], $this->supplierId);
        $resolver = new \MyInvoice\Service\Accounting\JournalSourceSummaryService($this->db);
        $summary = $resolver->summarize($this->supplierId, $entry);
        self::assertSame(['name' => 'invoice-detail', 'params' => ['id' => $invoice]], $summary['route']);
        self::assertFalse($summary['available']);
        self::assertSame('open_detail', $summary['actions'][0]['key']);
        self::assertNull($resolver->summarize(0, $entry)['route']);
        $zero = $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $invoice, 'legal_amount' => 0, 'acct_amount' => 0],
        ], $this->rv(), $this->meta());
        $lines = $this->entryLines($zero['entries'][0]['entry_id']);
        self::assertSame(15000.0, $this->sideAmount($lines, '391', 'debit'));
        self::assertSame($firstLines, $this->entryLines($firstEntry));
        $this->closing->revertStep($this->supplierId, $this->periodId, 'provisions', $this->rv(), $this->meta());
        self::assertSame([], $this->entryLines($zero['entries'][0]['entry_id']));
        self::assertSame($firstLines, $this->entryLines($firstEntry));
    }

    public function testZeroProvisionInNextYearNeverDeletesClosedYear(): void
    {
        $invoice = $this->receivable(50000, self::YEAR . '-02-01', '2096-04-30');
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $first = $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $invoice, 'acct_amount' => 10000],
        ], $this->rv(), $this->meta());
        $id = $first['entries'][0]['entry_id'];
        $before = $this->entryLines($id);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR + 1, '2098-01-01', '2098-12-31');
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runProvisions($this->supplierId, $this->periodId, [['invoice_id' => $invoice]], $this->rv(), $this->meta());
        self::assertSame($before, $this->entryLines($id));
    }

    public function testRepositoryRejectsDeletingProvisionFromClosedPeriod(): void
    {
        $invoice = $this->receivable(50000, self::YEAR . '-02-01', '2096-04-30');
        $this->posting->postDocument($this->supplierId, 'provision', $invoice, [
            ['account_code' => '558', 'side' => 'debit', 'amount' => 10000],
            ['account_code' => '391', 'side' => 'credit', 'amount' => 10000],
        ], ['entry_date' => self::ENDS_ON, 'posted_by' => $this->userId]);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        $repository = Bootstrap::buildApp()->getContainer()->get(\MyInvoice\Repository\ClosingRepository::class);
        $this->expectException(ClosingException::class);
        $repository->deleteClosingEntry($this->supplierId, 'provision', $invoice);
    }

    public function testPriorProvisionReversedInCurrentYearIsNotReleasedTwice(): void
    {
        $invoice = $this->receivable(50000, self::YEAR . '-02-01', '2096-04-30');
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $first = $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $invoice, 'legal_amount' => 10000, 'legal_section' => '8a'],
        ], $this->rv(), $this->meta());
        $id = $first['entries'][0]['entry_id'];
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR + 1, '2098-01-01', '2098-12-31');
        $reversal = $this->posting->reverse($this->supplierId, $id, $this->meta() + ['entry_date' => '2098-06-01']);
        $reversedLines = $this->entryLines($reversal);
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $zero = $this->closing->runProvisions($this->supplierId, $this->periodId, [['invoice_id' => $invoice]], $this->rv(), $this->meta());
        self::assertSame([], $zero['entries']);
        self::assertSame($reversedLines, $this->entryLines($reversal));
        $repository = Bootstrap::buildApp()->getContainer()->get(\MyInvoice\Repository\ClosingRepository::class);
        self::assertSame(0.0, $repository->provisionOpeningState($this->supplierId, '2099-01-01', '2099-12-31')[$invoice]['legal_amount']);
    }

    public function testProvisionsPreviewSuggests50PctFor20MonthsOverdue(): void
    {
        // Pohledávka 20 měsíců po splatnosti, nad 30 tis. → §8a 50 %.
        $invId = $this->receivable(50000.00, self::YEAR . '-02-01', '2096-04-30');

        $preview = $this->closing->provisionsPreview($this->supplierId, $this->periodId);
        $item = $this->findItem($preview['items'], $invId);

        self::assertNotNull($item, 'Pohledávka musí být v návrhu OP.');
        self::assertSame(20, $item['months_overdue']);
        self::assertSame('8a', $item['legal_section']);
        self::assertSame(0.5, $item['suggested_legal_pct']);
        self::assertEqualsWithDelta(25000.00, $item['suggested_legal_amount'], 0.001);
    }

    public function testProvisionPagesKeepFullDebtorAggregate(): void
    {
        $first = $this->receivable(20000.00, self::YEAR . '-02-01', '2096-11-30');
        $second = $this->receivable(20000.00, self::YEAR . '-02-01', '2096-11-30');
        $this->db->pdo()->prepare('UPDATE invoices SET client_id = (SELECT client_id FROM (SELECT client_id FROM invoices WHERE id = ?) x) WHERE id = ?')
            ->execute([$first, $second]);
        $page = $this->closing->provisionsPreview($this->supplierId, $this->periodId, 1, 1);
        self::assertCount(1, $page['items']);
        self::assertSame(2, $page['pagination']['total']);
        self::assertEqualsWithDelta(40000.0, $page['totals']['remaining'], 0.001);
        self::assertEqualsWithDelta(40000.0, $page['items'][0]['debtor_total_remaining'], 0.001);
        self::assertNull($page['items'][0]['legal_section']);
        $next = $this->closing->provisionsPreview($this->supplierId, $this->periodId, 2, 1);
        self::assertNotSame($page['items'][0]['invoice_id'], $next['items'][0]['invoice_id']);
        $pastEnd = $this->closing->provisionsPreview($this->supplierId, $this->periodId, 9, 1);
        self::assertSame(2, $pastEnd['pagination']['page']);
        self::assertCount(1, $pastEnd['items']);
    }

    public function testPartialProvisionRunPreservesOtherPagesAndRevertsAll(): void
    {
        $first = $this->receivable(50000.0, self::YEAR . '-02-01', '2096-04-30');
        $second = $this->receivable(50000.0, self::YEAR . '-02-01', '2096-04-30');
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $first, 'acct_amount' => 1000],
        ], $this->rv(), $this->meta(), true);
        $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $second, 'acct_amount' => 2000],
        ], $this->rv(), $this->meta(), true);
        self::assertNotNull($this->journal->findBySource($this->supplierId, 'provision', $first));
        self::assertNotNull($this->journal->findBySource($this->supplierId, 'provision', $second));
        $this->closing->revertStep($this->supplierId, $this->periodId, 'provisions', $this->rv(), $this->meta());
        self::assertNull($this->journal->findBySource($this->supplierId, 'provision', $first));
        self::assertNull($this->journal->findBySource($this->supplierId, 'provision', $second));
    }

    public function testProvisionsRemainUsableAboveSaldoReportLimit(): void
    {
        $seed = $this->receivable(10.0, self::YEAR . '-02-01', '2096-11-30');
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, client_id, issue_date, due_date, currency_id, created_by, total_with_vat, status)
             SELECT i.supplier_id, CONCAT("OPLIMIT", seq), i.client_id, i.issue_date, i.due_date, i.currency_id, i.created_by, i.total_with_vat, i.status
               FROM invoices i CROSS JOIN seq_1_to_25000 WHERE i.id = ?'
        )->execute([$seed]);
        $pdo->prepare(
            'INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, source_id, posted_at, posted_by)
             SELECT supplier_id, ?, issue_date, "invoice", id, NOW(), created_by FROM invoices
              WHERE supplier_id = ? AND id <> ?'
        )->execute([$this->periodId, $this->supplierId, $seed]);
        $pdo->prepare(
            'INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no)
             SELECT e.id, e.supplier_id, ca.id, IF(ca.account_code = "311", "debit", "credit"), 10.0, IF(ca.account_code = "311", 1, 2)
               FROM journal_entries e JOIN chart_of_accounts ca ON ca.supplier_id = e.supplier_id AND ca.account_code IN ("311", "602")
              WHERE e.supplier_id = ? AND e.source_type = "invoice" AND e.source_id <> ?'
        )->execute([$this->supplierId, $seed]);
        $repository = new \MyInvoice\Repository\ClosingRepository($this->db);
        $boundedState = function (string $startsOn, string $endsOn) use ($pdo, $repository): array {
            $timeout = $pdo->query('SELECT @@SESSION.max_statement_time')->fetchColumn();
            $pdo->exec('SET SESSION max_statement_time = 5');
            try {
                return $repository->provisionOpeningState($this->supplierId, $startsOn, $endsOn);
            } finally {
                $pdo->exec('SET SESSION max_statement_time = ' . sprintf('%.6F', (float) $timeout));
            }
        };
        self::assertSame([], $boundedState(self::YEAR . '-01-01', self::ENDS_ON));
        $preview = $this->closing->provisionsPreview($this->supplierId, $this->periodId);
        self::assertCount(100, $preview['items']);
        self::assertSame(25001, $preview['pagination']['total']);
        self::assertEqualsWithDelta(250010.0, $preview['totals']['remaining'], 0.001);
        self::assertEqualsWithDelta(250010.0, $preview['items'][0]['debtor_total_remaining'], 0.001);
        self::assertNull($preview['items'][0]['legal_section']);
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $result = $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $seed, 'acct_amount' => 5],
        ], $this->rv(), $this->meta(), true);
        self::assertSame(1, $result['count']);
        self::assertNotNull($this->journal->findBySource($this->supplierId, 'provision', $seed));
        $next = $this->periods->create($this->supplierId, self::YEAR + 1, '2098-01-01', '2098-12-31');
        $pdo->prepare(
            "INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, source_id, posted_at)
             SELECT supplier_id, ?, '2098-12-31', 'provision', id, NOW()
               FROM invoices WHERE supplier_id = ? AND id <> ?"
        )->execute([$next, $this->supplierId, $seed]);
        $pdo->prepare(
            "INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no)
             SELECT e.id, e.supplier_id, a.id, IF(a.account_code = '558', 'debit', 'credit'), 1, IF(a.account_code = '558', 1, 2)
               FROM journal_entries e
               JOIN chart_of_accounts a ON a.supplier_id = e.supplier_id AND a.account_code IN ('558', '391')
              WHERE e.supplier_id = ? AND e.period_id = ? AND e.source_type = 'provision'"
        )->execute([$this->supplierId, $next]);
        $state = $boundedState('2099-01-01', '2099-12-31');
        self::assertCount(25001, $state);
        self::assertSame(25000.0, array_sum(array_column($state, 'legal_amount')));
        self::assertSame(5.0, array_sum(array_column($state, 'acct_amount')));
    }

    public function testPartialProvisionRunRemovesPreviouslyOpenPaidInvoice(): void
    {
        $first = $this->receivable(50000.0, self::YEAR . '-02-01', '2096-04-30');
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $first, 'acct_amount' => 1000],
        ], $this->rv(), $this->meta(), true);
        $this->db->pdo()->prepare('INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source) VALUES (?, ?, ?, 50000, "CZK", "manual")')
            ->execute([$this->supplierId, $first, self::YEAR . '-06-30']);
        $this->closing->runProvisions($this->supplierId, $this->periodId, [], $this->rv(), $this->meta(), true);
        self::assertNull($this->journal->findBySource($this->supplierId, 'provision', $first));
    }

    public function testProvisionsPreviewSuggests100PctForSmallReceivableOver12Months(): void
    {
        // Drobná pohledávka do 30 tis., 13 měsíců po splatnosti → §8c 100 %.
        $invId = $this->receivable(20000.00, self::YEAR . '-02-01', '2096-11-30');

        $preview = $this->closing->provisionsPreview($this->supplierId, $this->periodId);
        $item = $this->findItem($preview['items'], $invId);

        self::assertNotNull($item);
        self::assertSame('8c', $item['legal_section']);
        self::assertSame(1.0, $item['suggested_legal_pct']);
        self::assertEqualsWithDelta(20000.00, $item['suggested_legal_amount'], 0.001);
    }

    public function testPotentiallyTimeBarredReceivableHasNoAutomaticLegalSuggestion(): void
    {
        $invId = $this->receivable(25000.00, self::YEAR . '-02-01', '2093-12-31');
        $item = $this->findItem(
            $this->closing->provisionsPreview($this->supplierId, $this->periodId)['items'],
            $invId,
        );

        self::assertNotNull($item);
        self::assertTrue($item['potentially_time_barred']);
        self::assertSame('receivable_may_be_time_barred', $item['warning']);
        self::assertNull($item['legal_section']);
        self::assertEqualsWithDelta(0.0, $item['suggested_legal_amount'], 0.001);
    }

    public function testRunProvisionsPostsLegalAllowanceIdempotentlyAndRemovesOnZero(): void
    {
        $invId = $this->receivable(50000.00, self::YEAR . '-02-01', '2096-04-30');
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        // Zaúčtuj zákonnou OP 50 % → 558/391 25 000.
        $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $invId, 'legal_amount' => 25000.00, 'acct_amount' => 0.0],
        ], $this->rv(), $this->meta());

        $entry = $this->journal->findBySource($this->supplierId, 'provision', $invId);
        self::assertNotNull($entry, 'OP musí být zaúčtovaná se source provision/invoice_id.');
        $entryId = (int) $entry['id'];
        $lines = $this->entryLines($entryId);
        self::assertEqualsWithDelta(25000.00, $this->sideAmount($lines, '558', 'debit'), 0.001);
        self::assertEqualsWithDelta(25000.00, $this->sideAmount($lines, '391', 'credit'), 0.001);

        // Re-run se stejnou částkou → tentýž zápis (idempotence per pohledávka).
        $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $invId, 'legal_amount' => 25000.00, 'acct_amount' => 0.0],
        ], $this->rv(), $this->meta());
        $entry2 = $this->journal->findBySource($this->supplierId, 'provision', $invId);
        self::assertSame($entryId, (int) $entry2['id'], 'Re-run nesmí vytvořit duplicitní zápis.');

        // Nulová OP → zápis se odstraní.
        $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $invId, 'legal_amount' => 0.0, 'acct_amount' => 0.0],
        ], $this->rv(), $this->meta());
        self::assertNull($this->journal->findBySource($this->supplierId, 'provision', $invId), 'Nulová OP musí zápis smazat.');
    }

    public function testAccountingProvisionUses559PWhichIsTaxNonDeductible(): void
    {
        $invId = $this->receivable(50000.00, self::YEAR . '-02-01', '2096-04-30');
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        // Zákonná 25 000 (558) + účetní nad rámec 25 000 (559P).
        $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $invId, 'legal_amount' => 25000.00, 'acct_amount' => 25000.00],
        ], $this->rv(), $this->meta());

        $lines = $this->entryLines((int) $this->journal->findBySource($this->supplierId, 'provision', $invId)['id']);
        self::assertEqualsWithDelta(25000.00, $this->sideAmount($lines, '558', 'debit'), 0.001);
        self::assertEqualsWithDelta(25000.00, $this->sideAmount($lines, '559P', 'debit'), 0.001);
        self::assertEqualsWithDelta(50000.00, $this->sideAmount($lines, '391', 'credit'), 0.001);

        // 559P je v osnově daňově neuznatelný → automaticky se propíše do úprav ZD (DPPO).
        $stmt = $this->db->pdo()->prepare(
            'SELECT tax_deductibility FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?'
        );
        $stmt->execute([$this->supplierId, '559P']);
        self::assertSame('non_deductible', (string) $stmt->fetchColumn());
        $stmt->execute([$this->supplierId, '558']);
        self::assertSame('deductible', (string) $stmt->fetchColumn(), '558 (zákonná OP) zůstává daňově uznatelná.');
    }

    public function testRunProvisionsRejectsAmountExceedingRemainingAfterPartialPayment(): void
    {
        // 20 měsíců po splatnosti, § 8a 50 % → 25 000, na počátku sedí do 50 000 zbývající.
        $invId = $this->receivable(50000.00, self::YEAR . '-02-01', '2096-04-30');
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $invId, 'legal_amount' => 25000.00, 'acct_amount' => 0.0],
        ], $this->rv(), $this->meta());

        // Dlužník mezitím uhradil 30 000 → zbývá jen 20 000, ale klient znovu pošle
        // starý návrh 25 000 (buď stale UI, nebo záměrný pokus obejít limit).
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source)
             VALUES (?, ?, ?, 30000.00, 'CZK', 'manual')"
        );
        $stmt->execute([$this->supplierId, $invId, self::YEAR . '-06-30']);

        try {
            $this->closing->runProvisions($this->supplierId, $this->periodId, [
                ['invoice_id' => $invId, 'legal_amount' => 25000.00, 'acct_amount' => 0.0],
            ], $this->rv(), $this->meta());
            self::fail('OP nad rámec zbývající hodnoty pohledávky měla být odmítnuta.');
        } catch (ClosingException $e) {
            self::assertSame('provision_exceeds_receivable', $e->errorCode);
        }

        // Zápis z prvního (platného) běhu zůstává nedotčený — žádný uszený stav.
        $entry = $this->journal->findBySource($this->supplierId, 'provision', $invId);
        self::assertNotNull($entry);
        self::assertEqualsWithDelta(25000.00, $this->sideAmount($this->entryLines((int) $entry['id']), '558', 'debit'), 0.001);

        // Menší, do zbývající hodnoty vejde se OP naopak projde bez problémů.
        $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $invId, 'legal_amount' => 20000.00, 'acct_amount' => 0.0],
        ], $this->rv(), $this->meta());
        $entry2 = $this->journal->findBySource($this->supplierId, 'provision', $invId);
        self::assertEqualsWithDelta(20000.00, $this->sideAmount($this->entryLines((int) $entry2['id']), '558', 'debit'), 0.001);
    }

    public function testRunProvisionsRejectsUnknownOrForeignInvoiceId(): void
    {
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        try {
            $this->closing->runProvisions($this->supplierId, $this->periodId, [
                ['invoice_id' => 999999999, 'legal_amount' => 1000.00, 'acct_amount' => 0.0],
            ], $this->rv(), $this->meta());
            self::fail('Neexistující/cizí invoice_id mělo být odmítnuto.');
        } catch (ClosingException $e) {
            self::assertSame('invoice_not_open_receivable', $e->errorCode);
        }
    }

    public function testAbortRejectsPostedProvisionEntry(): void
    {
        $invId = $this->receivable(50000.00, self::YEAR . '-02-01', '2096-04-30');
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runProvisions($this->supplierId, $this->periodId, [
            ['invoice_id' => $invId, 'legal_amount' => 25000.00],
        ], $this->rv(), $this->meta());

        try {
            $this->closing->abort($this->supplierId, $this->periodId, $this->rv(), $this->meta());
            self::fail('Zaúčtovaná OP musí přerušení uzávěrky blokovat.');
        } catch (ClosingException $e) {
            self::assertSame('closing_entries_exist', $e->errorCode);
        }
    }

    // ── D11: splatná daň z příjmů 591/341 ──────────────────────────────────────

    public function testIncomeTaxPostsAndIsIdempotent(): void
    {
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());

        $r1 = $this->closing->runIncomeTax($this->supplierId, $this->periodId, 42000.00, $this->rv(), $this->meta());
        $entry = $this->journal->findBySource($this->supplierId, 'income_tax', $this->periodId);
        self::assertNotNull($entry);
        $entryId = (int) $entry['id'];
        $lines = $this->entryLines($entryId);
        self::assertEqualsWithDelta(42000.00, $this->sideAmount($lines, '591', 'debit'), 0.001);
        self::assertEqualsWithDelta(42000.00, $this->sideAmount($lines, '341', 'credit'), 0.001);

        // Druhé zaúčtování (oprava částky) → tentýž zápis, žádná duplicita.
        $this->closing->runIncomeTax($this->supplierId, $this->periodId, 50000.00, $this->rv(), $this->meta());
        $entry2 = $this->journal->findBySource($this->supplierId, 'income_tax', $this->periodId);
        self::assertSame($entryId, (int) $entry2['id'], 'Opakované zaúčtování daně musí být idempotentní.');
        $lines2 = $this->entryLines($entryId);
        self::assertEqualsWithDelta(50000.00, $this->sideAmount($lines2, '591', 'debit'), 0.001);
    }

    public function testIncomeTaxRecordsDeviationFromSuggestion(): void
    {
        // Finalizované DPPO přiznání → známý návrh 63 840. Účetní zaúčtuje jinou částku
        // (70 000) s důvodem — částka se NEblokuje, ale odchylka (zdroj/návrh/rozdíl/důvod)
        // se eviduje v návratu i v payloadu kroku (EP-16).
        $this->db->pdo()->prepare(
            "INSERT INTO income_tax_returns (supplier_id, year, taxpayer_type, variant, status, inputs, computed, row_version, created_by)
             VALUES (?, ?, 'po', 'radne', 'final', '{}', ?, 1, ?)"
        )->execute([
            $this->supplierId,
            self::YEAR,
            json_encode(['computed' => ['summary' => ['total_tax' => 63840.0]]]),
            $this->userId,
        ]);

        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $result = $this->closing->runIncomeTax(
            $this->supplierId,
            $this->periodId,
            70000.00,
            $this->rv(),
            $this->meta(),
            'Dodatečná úprava základu daně mimo přiznání.',
        );

        self::assertSame('finalized_return', $result['suggested_source']);
        self::assertEqualsWithDelta(63840.00, (float) $result['suggested_amount'], 0.001);
        self::assertEqualsWithDelta(6160.00, (float) $result['difference'], 0.001);
        self::assertSame('Dodatečná úprava základu daně mimo přiznání.', $result['reason']);

        // Odchylka je i v uloženém payloadu kroku (dohledatelná po znovunačtení přes state()).
        $steps = $this->closing->state($this->supplierId, $this->periodId)['steps'];
        $incomeTax = null;
        foreach ($steps as $s) {
            if (($s['step_key'] ?? null) === 'income_tax') {
                $incomeTax = $s;
                break;
            }
        }
        self::assertNotNull($incomeTax);
        self::assertEqualsWithDelta(63840.00, (float) $incomeTax['payload']['suggested_amount'], 0.001);
        self::assertEqualsWithDelta(6160.00, (float) $incomeTax['payload']['difference'], 0.001);
        self::assertSame('Dodatečná úprava základu daně mimo přiznání.', $incomeTax['payload']['reason']);
    }

    public function testIncomeTaxZeroRejected(): void
    {
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->expectException(ClosingException::class);
        $this->expectExceptionMessageMatches('/kladn/i');
        $this->closing->runIncomeTax($this->supplierId, $this->periodId, 0.0, $this->rv(), $this->meta());
    }

    public function testIncomeTaxPreviewPrefillsFromFinalizedDppoReturn(): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "INSERT INTO income_tax_returns (supplier_id, year, taxpayer_type, variant, status, inputs, computed, row_version, created_by)
             VALUES (?, ?, 'po', 'radne', 'final', '{}', ?, 1, ?)"
        );
        $stmt->execute([
            $this->supplierId,
            self::YEAR,
            json_encode(['computed' => ['summary' => ['total_tax' => 63840.0]]]),
            $this->userId,
        ]);

        $preview = $this->closing->incomeTaxPreview($this->supplierId, $this->periodId);
        self::assertTrue($preview['has_finalized_return']);
        self::assertSame('finalized_return', $preview['suggested_source']);
        self::assertEqualsWithDelta(63840.0, (float) $preview['suggested_amount'], 0.001);
    }

    /**
     * Bez finalizovaného DPPO přiznání se ř.340 dopočítá z aktuálního účetnictví
     * (VH z posted deníku → základ zaokrouhlený dolů na tisíce × 21 %), ne z null.
     */
    public function testIncomeTaxPreviewComputesFromLedgerWhenNoFinalizedReturn(): void
    {
        // VH = 245 678,00 Kč (žádné nedaňové náklady/odpisy/vyřazení v období) →
        // základ stejný, zaokrouhlený dolů na tisíce (245 000) × 21 % = 51 450,00.
        $this->receivable(245678.00, self::YEAR . '-03-01', self::YEAR . '-04-01');

        $preview = $this->closing->incomeTaxPreview($this->supplierId, $this->periodId);
        self::assertFalse($preview['has_finalized_return']);
        self::assertSame('computed_from_ledger', $preview['suggested_source']);
        self::assertEqualsWithDelta(51450.00, (float) $preview['suggested_amount'], 0.001);
    }

    public function testIncomeTaxIsNotApplicableToIndividualEntrepreneur(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET taxpayer_type = 'fo' WHERE id = ?")
            ->execute([$this->supplierId]);
        $preview = $this->closing->incomeTaxPreview($this->supplierId, $this->periodId);
        self::assertFalse($preview['applicable']);
        self::assertSame('fo', $preview['taxpayer_type']);
        self::assertNull($preview['suggested_amount']);
        self::assertNull($preview['suggested_source']);

        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        try {
            $this->closing->runIncomeTax($this->supplierId, $this->periodId, 10000.00, $this->rv(), $this->meta());
            self::fail('DPFO podnikatele se nesmí účtovat na 591/341.');
        } catch (ClosingException $e) {
            self::assertSame('income_tax_not_applicable', $e->errorCode);
        }
    }

    public function testAbortRejectsPostedIncomeTaxEntry(): void
    {
        $this->closing->start($this->supplierId, $this->periodId, $this->rv(), $this->meta());
        $this->closing->runIncomeTax($this->supplierId, $this->periodId, 42000.00, $this->rv(), $this->meta());

        try {
            $this->closing->abort($this->supplierId, $this->periodId, $this->rv(), $this->meta());
            self::fail('Zaúčtovaný předpis daně musí přerušení uzávěrky blokovat.');
        } catch (ClosingException $e) {
            self::assertSame('closing_entries_exist', $e->errorCode);
        }
    }

    // ── D10: rozdělení výsledku hospodaření ────────────────────────────────────

    public function testProfitDistributionRejectsSumOver431(): void
    {
        [$approvedId, $target] = $this->approvedPeriodWithProfit(100000.00);

        $this->expectException(ClosingException::class);
        $this->expectExceptionMessageMatches('/neshoduje|431/i');
        $this->closing->runProfitDistribution($this->supplierId, $approvedId, [
            'decision_date' => $target['fiscal_year'] . '-06-30',
            'target_row_version' => (int) $target['row_version'],
            'allocations' => [
                ['account_code' => '428', 'amount' => 150000.00, 'kind' => 'retained'],
            ],
        ], $this->meta());
    }

    public function testProfitDistributionClears431AndWithholdsShares(): void
    {
        [$approvedId, $target] = $this->approvedPeriodWithProfit(100000.00);
        $targetId = (int) $target['id'];

        $res = $this->closing->runProfitDistribution($this->supplierId, $approvedId, [
            'decision_date' => $target['fiscal_year'] . '-06-30',
            'target_row_version' => (int) $target['row_version'],
            'allocations' => [
                ['account_code' => '428', 'amount' => 60000.00, 'kind' => 'retained'],
                ['account_code' => '364', 'amount' => 40000.00, 'kind' => 'shares'],
            ],
        ], $this->meta());

        self::assertEqualsWithDelta(6000.00, (float) $res['withholding'], 0.001, 'Srážková daň 15 % z podílů 40 000.');
        $lines = $this->entryLines((int) $res['entry_id']);
        self::assertEqualsWithDelta(100000.00, $this->sideAmount($lines, '431', 'debit'), 0.001);
        self::assertEqualsWithDelta(60000.00, $this->sideAmount($lines, '428', 'credit'), 0.001);
        // 364: 40 000 kredit (příděl) − 6 000 debet (srážka) = 34 000 netto kredit.
        self::assertEqualsWithDelta(40000.00, $this->sideAmount($lines, '364', 'credit'), 0.001);
        self::assertEqualsWithDelta(6000.00, $this->sideAmount($lines, '364', 'debit'), 0.001);
        self::assertEqualsWithDelta(6000.00, $this->sideAmount($lines, '342', 'credit'), 0.001);

        // 431 v cílovém období je po rozdělení vynulovaný.
        $balance431 = $this->closing->profitDistributionPreview($this->supplierId, $approvedId)['balance_431'];
        self::assertEqualsWithDelta(0.0, (float) $balance431, 0.001);

        // Revert (hard delete) obnoví zůstatek 431.
        $freshTarget = $this->periods->findById($this->supplierId, $targetId);
        $this->closing->revertProfitDistribution($this->supplierId, $approvedId, (int) $freshTarget['row_version'], $this->meta());
        self::assertNull($this->journal->findBySource($this->supplierId, 'profit_distribution', $approvedId));
    }

    public function testProfitDistributionRevertRejectedAfterFollowUpSharePayment(): void
    {
        [$approvedId, $target] = $this->approvedPeriodWithProfit(100000.00);
        $decisionDate = $target['fiscal_year'] . '-06-30';

        $this->closing->runProfitDistribution($this->supplierId, $approvedId, [
            'decision_date' => $decisionDate,
            'target_row_version' => (int) $target['row_version'],
            'allocations' => [
                ['account_code' => '428', 'amount' => 60000.00, 'kind' => 'retained'],
                ['account_code' => '364', 'amount' => 40000.00, 'kind' => 'shares'],
            ],
        ], $this->meta());

        // Výplata podílu společníkovi PO datu rozhodnutí VH — MD 364 / D 221.
        $this->manual([
            self::l('364', 'debit', 34000.00),
            self::l('221', 'credit', 34000.00),
        ], $target['fiscal_year'] . '-07-15');

        $freshTarget = $this->periods->findById($this->supplierId, (int) $target['id']);
        try {
            $this->closing->revertProfitDistribution($this->supplierId, $approvedId, (int) $freshTarget['row_version'], $this->meta());
            self::fail('Revert rozdělení VH po navazující výplatě podílu měl být odmítnut.');
        } catch (ClosingException $e) {
            self::assertSame('distribution_settled', $e->errorCode);
        }

        // Zápis rozdělení zůstává zaúčtovaný (revert se neprovedl).
        self::assertNotNull($this->journal->findBySource($this->supplierId, 'profit_distribution', $approvedId));
    }

    public function testProfitDistributionCoversLossByDebitingEquityAccount(): void
    {
        [$approvedId, $target] = $this->approvedPeriodWithLoss(80000.00);

        $res = $this->closing->runProfitDistribution($this->supplierId, $approvedId, [
            'decision_date' => $target['fiscal_year'] . '-06-30',
            'target_row_version' => (int) $target['row_version'],
            'allocations' => [
                ['account_code' => '428', 'amount' => 80000.00, 'kind' => 'loss_coverage'],
            ],
        ], $this->meta());

        self::assertTrue($res['is_loss']);
        self::assertEqualsWithDelta(0.0, (float) $res['withholding'], 0.001, 'Úhrada ztráty nesráží srážkovou daň.');
        $lines = $this->entryLines((int) $res['entry_id']);
        self::assertEqualsWithDelta(80000.00, $this->sideAmount($lines, '428', 'debit'), 0.001);
        self::assertEqualsWithDelta(80000.00, $this->sideAmount($lines, '431', 'credit'), 0.001);

        $balance431 = $this->closing->profitDistributionPreview($this->supplierId, $approvedId)['balance_431'];
        self::assertEqualsWithDelta(0.0, (float) $balance431, 0.001);
    }

    public function testProfitDistributionRerunRewritesEntryInPlace(): void
    {
        [$approvedId, $target] = $this->approvedPeriodWithProfit(100000.00);
        $targetId = (int) $target['id'];

        $res1 = $this->closing->runProfitDistribution($this->supplierId, $approvedId, [
            'decision_date' => $target['fiscal_year'] . '-06-30',
            'target_row_version' => (int) $target['row_version'],
            'allocations' => [
                ['account_code' => '428', 'amount' => 100000.00, 'kind' => 'retained'],
            ],
        ], $this->meta());

        // Účetní si to rozmyslí — chce místo celého 428 poslat část na podíly.
        // Re-run se stejným source_id musí zápis PŘEPSAT in-place, ne založit druhý
        // (audit 2026-07 #3 — bez exclude vlastního zápisu z accountBalance(431) by
        // available_profit vyšla 0 a druhý běh by vždy spadl na distribution_mismatch).
        $freshTarget = $this->periods->findById($this->supplierId, $targetId);
        $res2 = $this->closing->runProfitDistribution($this->supplierId, $approvedId, [
            'decision_date' => $target['fiscal_year'] . '-06-30',
            'target_row_version' => (int) $freshTarget['row_version'],
            'allocations' => [
                ['account_code' => '428', 'amount' => 60000.00, 'kind' => 'retained'],
                ['account_code' => '364', 'amount' => 40000.00, 'kind' => 'shares'],
            ],
        ], $this->meta());

        self::assertSame($res1['entry_id'], $res2['entry_id'], 'Re-run musí přepsat stejný zápis (idempotence), ne založit nový.');
        self::assertEqualsWithDelta(100000.00, (float) $res2['available_profit'], 0.001);
        $lines = $this->entryLines((int) $res2['entry_id']);
        self::assertEqualsWithDelta(60000.00, $this->sideAmount($lines, '428', 'credit'), 0.001);
        self::assertEqualsWithDelta(40000.00, $this->sideAmount($lines, '364', 'credit'), 0.001);
        self::assertEqualsWithDelta(6000.00, $this->sideAmount($lines, '342', 'credit'), 0.001);

        // Jediný zápis v deníku pro tento source — žádná duplicita.
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = 'profit_distribution' AND source_id = ?"
        );
        $stmt->execute([$this->supplierId, $approvedId]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testOpenPeriodDistributionUsesApprovedPreviousPeriodAsSource(): void
    {
        [$approvedId, $target] = $this->approvedPeriodWithProfit(100000.00);
        $res = $this->closing->runProfitDistribution($this->supplierId, (int) $target['id'], [
            'decision_date' => $target['fiscal_year'] . '-06-30',
            'target_row_version' => (int) $target['row_version'],
            'allocations' => [
                ['account_code' => '428', 'amount' => 100000.00, 'kind' => 'retained'],
            ],
        ], $this->meta());

        self::assertNotNull($this->journal->findBySource($this->supplierId, 'profit_distribution', $approvedId));
        self::assertNull($this->journal->findBySource($this->supplierId, 'profit_distribution', (int) $target['id']));
        self::assertSame((int) $target['id'], (int) $res['target_period_id']);
    }

    /**
     * Zpětné rozdělení VH nad rozdělanou uzávěrkou. Kdo zahájil uzávěrku dřív, než rozdělil
     * loňský výsledek, se dřív zasekl: uzavření knih blokuje precheck vh_431_undistributed,
     * přerušit uzávěrku po prvních uzávěrkových zápisech nejde a rozdělení se nad obdobím
     * ve stavu 'closing' vůbec nenabízelo.
     */
    public function testProfitDistributionWorksIntoPeriodBeingClosed(): void
    {
        [$approvedId, $target] = $this->approvedPeriodWithProfit(100000.00);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closing' WHERE id = ? AND supplier_id = ?")
            ->execute([(int) $target['id'], $this->supplierId]);
        $target = $this->periods->findById($this->supplierId, (int) $target['id']);

        $res = $this->closing->runProfitDistribution($this->supplierId, $approvedId, [
            'decision_date' => $target['fiscal_year'] . '-06-30',
            'target_row_version' => (int) $target['row_version'],
            'allocations' => [
                ['account_code' => '428', 'amount' => 100000.00, 'kind' => 'retained'],
            ],
        ], $this->meta());

        $lines = $this->entryLines((int) $res['entry_id']);
        self::assertEqualsWithDelta(100000.00, $this->sideAmount($lines, '431', 'debit'), 0.001);
        self::assertEqualsWithDelta(100000.00, $this->sideAmount($lines, '428', 'credit'), 0.001);
        self::assertEqualsWithDelta(
            0.0,
            (float) $this->closing->profitDistributionPreview($this->supplierId, $approvedId)['balance_431'],
            0.001,
            '431 je po zpětném rozdělení vynulovaný i v uzavíraném období.',
        );
    }

    /** Uzavřené období zůstává pro rozdělení zakázané — tam už se účtovat nesmí (§35 ZoÚ). */
    public function testProfitDistributionRejectsClosedTargetPeriod(): void
    {
        [$approvedId, $target] = $this->approvedPeriodWithProfit(100000.00);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ? AND supplier_id = ?")
            ->execute([(int) $target['id'], $this->supplierId]);

        try {
            $this->closing->profitDistributionPreview($this->supplierId, $approvedId);
            self::fail('Do uzavřeného období se rozdělení VH účtovat nesmí.');
        } catch (ClosingException $e) {
            self::assertSame('next_period_not_open', $e->errorCode);
        }
    }

    /** Uzávěrku nelze zahájit s nerozděleným výsledkem na 431 — jinak se uživatel zasekne. */
    public function testClosingCannotStartWithUndistributedProfitOn431(): void
    {
        [, $target] = $this->approvedPeriodWithProfit(100000.00);
        $targetId = (int) $target['id'];

        try {
            $this->closing->start($this->supplierId, $targetId, (int) $target['row_version'], $this->meta());
            self::fail('Uzávěrka se nesmí zahájit s nerozděleným VH na 431.');
        } catch (ClosingException $e) {
            self::assertSame('profit_not_distributed', $e->errorCode);
        }
        self::assertSame(
            'open',
            (string) $this->periods->findById($this->supplierId, $targetId)['status'],
            'Odmítnuté zahájení nesmí období přepnout.',
        );
    }

    public function testOpenPeriodDistributionRequiresApprovedPreviousPeriod(): void
    {
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ? AND supplier_id = ?")
            ->execute([$this->periodId, $this->supplierId]);
        $nextYear = self::YEAR + 1;
        $nextId = $this->periods->create($this->supplierId, $nextYear, $nextYear . '-01-01', $nextYear . '-12-31');

        try {
            $this->closing->profitDistributionPreview($this->supplierId, $nextId);
            self::fail('Otevřené období bez schváleného předchůdce nesmí zpřístupnit rozdělení VH.');
        } catch (ClosingException $e) {
            self::assertSame('profit_distribution_requires_approved_period', $e->errorCode);
        }
    }

    public function testProfitSharesCannotExceedDistributableResourcesAfterUncoveredLoss(): void
    {
        [$approvedId, $target] = $this->approvedPeriodWithProfit(100000.00);
        $this->manual([
            self::l('429', 'debit', 120000.00),
            self::l('221', 'credit', 120000.00),
        ], $target['fiscal_year'] . '-01-02');

        try {
            $this->closing->runProfitDistribution($this->supplierId, $approvedId, [
                'decision_date' => $target['fiscal_year'] . '-06-30',
                'target_row_version' => (int) $target['row_version'],
                'allocations' => [
                    ['account_code' => '364', 'amount' => 100000.00, 'kind' => 'shares'],
                ],
            ], $this->meta());
            self::fail('Podíly nad rozdělitelné zdroje musí být odmítnuty.');
        } catch (ClosingException $e) {
            self::assertSame('insufficient_distributable_resources', $e->errorCode);
        }
    }

    public function testWithholdingTaxIsRoundedDownPerShareholderRow(): void
    {
        [$approvedId, $target] = $this->approvedPeriodWithProfit(20001.00);
        $res = $this->closing->runProfitDistribution($this->supplierId, $approvedId, [
            'decision_date' => $target['fiscal_year'] . '-06-30',
            'target_row_version' => (int) $target['row_version'],
            'allocations' => [
                ['account_code' => '364', 'amount' => 10000.99, 'kind' => 'shares'],
                ['account_code' => '364', 'amount' => 10000.01, 'kind' => 'shares'],
            ],
        ], $this->meta());

        self::assertEqualsWithDelta(3000.00, (float) $res['withholding'], 0.001);
        $lines = $this->entryLines((int) $res['entry_id']);
        self::assertEqualsWithDelta(3000.00, $this->sideAmount($lines, '342', 'credit'), 0.001);
        self::assertEqualsWithDelta(3000.00, $this->sideAmount($lines, '364', 'debit'), 0.001);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /** Založí klienta + vydanou fakturu a zaúčtuje otevřenou pohledávku 311/602. */
    private function receivable(float $total, string $issue, string $due): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, "Ulice 1", "Praha", "11000", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, 'Odběratel ' . uniqid(), $this->czId, 'c' . uniqid() . '@example.com', $this->currencyId]);
        $clientId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, client_id, issue_date, due_date, currency_id, created_by, total_with_vat, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "issued")'
        );
        $stmt->execute([$this->supplierId, (string) random_int(1000000000, 1999999999), $clientId, $issue, $due, $this->currencyId, $this->userId, $total]);
        $invId = (int) $pdo->lastInsertId();

        $this->posting->postDocument($this->supplierId, 'invoice', $invId, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => $total],
            ['account_code' => '602', 'side' => 'credit', 'amount' => $total],
        ], ['entry_date' => $issue, 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        return $invId;
    }

    /**
     * Schválené období + následující otevřené s VH na 431 (kredit = zisk).
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function approvedPeriodWithProfit(float $profit): array
    {
        $pdo = $this->db->pdo();
        // setUp období self::YEAR → schválené.
        $pdo->prepare("UPDATE accounting_periods SET status = 'approved', approved_at = NOW() WHERE id = ? AND supplier_id = ?")
            ->execute([$this->periodId, $this->supplierId]);

        $nextYear = self::YEAR + 1;
        $nextId = $this->periods->create($this->supplierId, $nextYear, $nextYear . '-01-01', $nextYear . '-12-31');
        // VH převedený otevíracím zápisem: MD 701-náhrada / D 431. Pro test stačí MD 221 / D 431.
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => $profit],
            ['account_code' => '431', 'side' => 'credit', 'amount' => $profit],
        ], ['entry_date' => $nextYear . '-01-01', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        return [$this->periodId, $this->periods->findById($this->supplierId, $nextId)];
    }

    /**
     * Schválené období + následující otevřené s neuhrazenou ztrátou na 431 (debet).
     * @return array{0:int, 1:array<string,mixed>}
     */
    private function approvedPeriodWithLoss(float $loss): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE accounting_periods SET status = 'approved', approved_at = NOW() WHERE id = ? AND supplier_id = ?")
            ->execute([$this->periodId, $this->supplierId]);

        $nextYear = self::YEAR + 1;
        $nextId = $this->periods->create($this->supplierId, $nextYear, $nextYear . '-01-01', $nextYear . '-12-31');
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '431', 'side' => 'debit', 'amount' => $loss],
            ['account_code' => '221', 'side' => 'credit', 'amount' => $loss],
        ], ['entry_date' => $nextYear . '-01-01', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        return [$this->periodId, $this->periods->findById($this->supplierId, $nextId)];
    }

    /**
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     */
    private function manual(array $lines, string $date): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            $lines,
            ['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId],
        );
    }

    /** @return array{account_code:string, side:string, amount:float} */
    private static function l(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }

    private function rv(): int
    {
        return (int) $this->periods->findById($this->supplierId, $this->periodId)['row_version'];
    }

    /** @return array{user_id:int, posted_by:int} */
    private function meta(): array
    {
        return ['user_id' => $this->userId, 'posted_by' => $this->userId];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>|null
     */
    private function findItem(array $items, int $invoiceId): ?array
    {
        foreach ($items as $it) {
            if ((int) $it['invoice_id'] === $invoiceId) {
                return $it;
            }
        }
        return null;
    }

    /** @return list<array{account_code:string, side:string, amount:float}> */
    private function entryLines(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, l.side, l.amount
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ?'
        );
        $stmt->execute([$entryId, $this->supplierId]);
        return array_map(static fn (array $r): array => [
            'account_code' => (string) $r['account_code'],
            'side' => (string) $r['side'],
            'amount' => (float) $r['amount'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<array{account_code:string, side:string, amount:float}> $lines */
    private function sideAmount(array $lines, string $code, string $side): float
    {
        $sum = 0.0;
        foreach ($lines as $l) {
            if ($l['account_code'] === $code && $l['side'] === $side) {
                $sum += $l['amount'];
            }
        }
        return round($sum, 2);
    }
}
