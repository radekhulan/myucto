<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Bank\StatementBalanceService;
use MyInvoice\Service\Migration\Shared\BankStatementImportWriter;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxImporter;
use MyInvoice\Service\Migration\StereoNx\StereoNxImportMap;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class StereoNxBankOpeningWriterTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxImporter $importer;
    private int $supplierId;
    private int $userId;
    private int $entryId;
    private array $plan;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->importer = $container->get(StereoNxImporter::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn());
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $periodId = $container->get(AccountingPeriodRepository::class)->create($this->supplierId, 2025, '2025-01-01', '2025-12-31');
        $chart = $container->get(ChartOfAccountsRepository::class);
        $bankId = $chart->insert($this->supplierId, ['account_code' => '221.710', 'name' => 'Syntetická banka', 'account_type' => 'asset', 'normal_side' => 'debit']);
        $openingId = $chart->insert($this->supplierId, ['account_code' => '701', 'name' => 'Počáteční účet', 'account_type' => 'equity', 'normal_side' => 'credit']);
        $entry = ['source_key' => '["B","B","0","1","1"]', 'date' => '2025-01-01', 'posting_year' => 2025,
            'debit' => '221.710', 'credit' => '701', 'amount_cents' => 100025, 'is_opening' => true, 'is_red_storno' => false];
        $entry['source_hash'] = StereoNxImportMap::fingerprint($entry);
        $this->entryId = $container->get(JournalEntryRepository::class)->insert([
            'supplier_id' => $this->supplierId, 'period_id' => $periodId, 'entry_date' => $entry['date'],
            'source_type' => 'opening', 'posted_at' => '2025-01-01 12:00:00', 'posted_by' => $this->userId,
        ], [['account_id' => $bankId, 'side' => 'debit', 'amount' => '1000.25', 'line_no' => 1],
            ['account_id' => $openingId, 'side' => 'credit', 'amount' => '1000.25', 'line_no' => 2]]);
        $container->get(StereoNxImportMap::class)->put($this->supplierId, '12345678', 0,
            'accounting_journal', $entry['source_key'], $entry['source_hash'], $this->entryId);
        $movement = ['source_key' => '["2025","B","0","1"]', 'statement_key' => '["2025","B","0"]',
            'date' => '2025-01-01', 'amount' => 1000.25, 'currency' => 'CZK', 'document_no' => 'SYN-OPEN',
            'description' => 'Syntetické otevření účtu'];
        $this->plan = ['identity' => ['ico' => '12345678'], 'source_company_index' => 0,
            'bank_accounts' => [['source_key' => 'B', 'account_number' => '19-1000000005', 'bank_code' => '0100',
                'currency' => 'CZK', 'target_analytic_suffix' => '710']],
            'bank_statements' => [['source_key' => $movement['statement_key'], 'account_key' => 'B',
                'date' => '2025-01-01', 'document_no' => 'SYN-OPEN']],
            'bank_transactions' => [], 'bank_openings' => [['movement' => $movement,
                'statement_key' => $movement['statement_key'], 'opening_cents' => 100025,
                'entry_keys' => [$entry['source_key']], 'account_code' => '221.710',
                'date' => '2025-01-01', 'balance_date' => '2024-12-31']],
            'journal_plan' => ['accounting_plan' => ['entries' => [$entry]]]];
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testFreshAnchorIsIdempotentAndCarriesOnlyRealTurnover(): void
    {
        $this->addMovement('1', '2025-01-01', 25.10);
        $this->addMovement('2', '2025-02-03', -10.05);
        $result = $this->write();
        $header = $this->openingStatement();
        self::assertSame('2024-12-31', $header['statement_date']);
        self::assertSame('1000.25', $header['prev_balance']);
        self::assertSame('1000.25', $header['curr_balance']);
        self::assertSame('0.00', $header['credit_total']);
        self::assertSame('0.00', $header['debit_total']);
        self::assertSame(0, (int) $header['transaction_count']);
        self::assertSame(2, $result['counts']['bank_transactions']);
        self::assertSame(1, $this->rowCount('journal_entries'));
        $statements = $this->rows('SELECT id, statement_number FROM bank_statements WHERE supplier_id=? ORDER BY id');
        $balance = new StatementBalanceService($this->db);
        $january = $balance->snapshot($this->supplierId, (int) $statements[1]['id']);
        self::assertEquals(1000.25, $january['opening']);
        self::assertEquals(25.10, $january['credit']);
        self::assertEquals(1025.35, $january['closing']);
        $february = $balance->snapshot($this->supplierId, (int) $statements[2]['id']);
        self::assertEquals(1025.35, $february['opening']);
        self::assertEquals(10.05, $february['debit']);
        self::assertEquals(1015.30, $february['closing']);
        $before = $this->snapshot();
        $repeat = $this->write();
        self::assertSame(0, $repeat['counts']['bank_transactions']);
        self::assertSame($before, $this->snapshot());
    }

    public function testCallerRollbackRemovesAnchorAndItsMap(): void
    {
        $before = $this->snapshot();
        $this->db->pdo()->exec('SAVEPOINT bank_opening_dry');
        $this->write();
        self::assertSame('1000.25', $this->openingStatement()['curr_balance']);
        self::assertTrue($this->db->pdo()->inTransaction());
        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT bank_opening_dry');
        self::assertSame($before, $this->snapshot());
    }

    public function testNegativeOpeningIsNotAnExpense(): void
    {
        $this->db->pdo()->prepare("UPDATE journal_entry_lines SET side=IF(side='debit','credit','debit') WHERE supplier_id=? AND entry_id=?")
            ->execute([$this->supplierId, $this->entryId]);
        $entry = &$this->plan['journal_plan']['accounting_plan']['entries'][0];
        [$entry['debit'], $entry['credit']] = [$entry['credit'], $entry['debit']];
        unset($entry['source_hash']);
        $entry['source_hash'] = StereoNxImportMap::fingerprint($entry);
        $this->db->pdo()->prepare("UPDATE stereo_nx_import_map SET source_hash=? WHERE supplier_id=? AND kind='accounting_journal'")
            ->execute([$entry['source_hash'], $this->supplierId]);
        $this->plan['bank_openings'][0]['movement']['amount'] = -1000.25;
        $this->plan['bank_openings'][0]['opening_cents'] = -100025;
        $this->write();
        self::assertSame('-1000.25', $this->openingStatement()['curr_balance']);
        self::assertSame('0.00', $this->openingStatement()['debit_total']);
    }

    public function testLegacyMovementRequiresExplicitRepairAndRollbackKeepsIt(): void
    {
        $original = $this->plan;
        $this->plan['bank_transactions'] = [$this->plan['bank_openings'][0]['movement']];
        unset($this->plan['bank_openings']);
        $this->write();
        $this->plan = $original;
        $before = $this->snapshot();
        $this->assertRejected('bank_opening_repair_required');
        self::assertSame($before, $this->snapshot());
        self::assertSame(1, $this->rowCount('journal_entries'));
        self::assertNull($this->openingStatement()['curr_balance']);
    }

    #[DataProvider('changedOpening')]
    public function testChangedLedgerCannotBecomeAnAnchor(string $sql): void
    {
        $this->db->pdo()->prepare($sql)->execute([$this->supplierId, $this->entryId]);
        $before = $this->snapshot();
        $this->assertRejected('bank_opening_unverified');
        self::assertSame($before, $this->snapshot());
    }

    public static function changedOpening(): array
    {
        return [
            'částka' => ['UPDATE journal_entry_lines SET amount=amount+1 WHERE supplier_id=? AND entry_id=?'],
            'koncept' => ['UPDATE journal_entries SET posted_at=NULL WHERE supplier_id=? AND id=?'],
            'storno' => ['UPDATE journal_entries SET reversed_by=id WHERE supplier_id=? AND id=?'],
            'datum' => ["UPDATE journal_entries SET entry_date='2025-01-02' WHERE supplier_id=? AND id=?"],
            'druh' => ["UPDATE journal_entries SET source_type='manual' WHERE supplier_id=? AND id=?"],
        ];
    }

    public function testOrdinaryTransactionInOpeningStatementBlocksAnchor(): void
    {
        $movement = $this->plan['bank_openings'][0]['movement'];
        $movement['source_key'] = '["2025","B","0","2"]';
        $movement['amount'] = 5.00;
        $this->plan['bank_transactions'][] = $movement;
        $this->assertRejected('bank_opening_unverified');
    }

    #[DataProvider('changedCounters')]
    public function testRepeatRejectsChangedAnchorCounters(string $column, mixed $value): void
    {
        $this->write();
        $id = (int) $this->openingStatement()['id'];
        $this->db->pdo()->prepare("UPDATE bank_statements SET {$column}=? WHERE supplier_id=? AND id=?")
            ->execute([$value, $this->supplierId, $id]);
        $before = $this->snapshot();
        $this->assertRejected('bank_opening_unverified');
        self::assertSame($before, $this->snapshot());
    }

    public static function changedCounters(): array
    {
        return [['credit_total', '1.00'], ['debit_total', '1.00'], ['credit_total', null],
            ['transaction_count', 1], ['matched_count', 1]];
    }

    #[DataProvider('conflictingAnchors')]
    public function testOtherAnchorOnSameDayIsRejected(bool $repeat, string $source): void
    {
        if ($repeat) $this->write();
        $this->otherAnchor('2024-12-31', $source);
        $before = $this->snapshot();
        $this->assertRejected('bank_opening_conflict');
        self::assertSame($before, $this->snapshot());
    }

    public static function conflictingAnchors(): array
    {
        return [[false, 'gpc'], [true, 'gpc'], [false, 'bank_api']];
    }

    public function testLaterAnchorDoesNotBlockOpening(): void
    {
        $this->otherAnchor('2025-01-31', 'gpc');
        $this->write();
        self::assertSame('1000.25', $this->openingStatement()['curr_balance']);
    }

    private function otherAnchor(string $date, string $source): void
    {
        $writer = new BankStatementImportWriter($this->db, 'synthetic-opening-test');
        // Bankovní GPC může tentýž fyzický účet uvádět nulami doplněným zápisem.
        $id = $writer->createStatement($this->supplierId, 'other-anchor', 'SYN-OTHER', '0000191000000005',
            '0100', 'CZK', $date, $this->userId);
        $writer->setBalancesInCents($this->supplierId, $id, 100025, 100025, 0, 0);
        $this->db->pdo()->prepare('UPDATE bank_statements SET source=?, file_content=? WHERE supplier_id=? AND id=?')
            ->execute([$source, 'synthetic', $this->supplierId, $id]);
    }

    private function addMovement(string $number, string $date, float $amount): void
    {
        $key = json_encode(['2025', 'B', $number], JSON_THROW_ON_ERROR);
        $this->plan['bank_statements'][] = ['source_key' => $key, 'account_key' => 'B', 'date' => $date, 'document_no' => 'SYN-' . $number];
        $this->plan['bank_transactions'][] = ['source_key' => json_encode(['2025', 'B', $number, '1'], JSON_THROW_ON_ERROR),
            'statement_key' => $key, 'date' => $date, 'currency' => 'CZK', 'amount' => $amount];
    }

    private function assertRejected(string $code): void
    {
        $this->db->pdo()->exec('SAVEPOINT bank_opening_reject');
        try {
            $this->write();
            self::fail('Neověřené otevření musí zastavit převod.');
        } catch (StereoNxException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
        } finally {
            $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT bank_opening_reject');
        }
    }

    private function write(): array
    {
        return $this->importer->writeAccountingPayments($this->plan, $this->supplierId, $this->userId);
    }

    private function openingStatement(): array
    {
        return $this->rows("SELECT * FROM bank_statements WHERE supplier_id=? AND statement_number='SYN-OPEN'")[0];
    }

    private function rows(string $sql): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$this->supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function rowCount(string $table): int
    {
        return (int) $this->rows("SELECT COUNT(*) AS n FROM {$table} WHERE supplier_id=?")[0]['n'];
    }

    private function snapshot(): array
    {
        return [$this->rows('SELECT * FROM bank_statements WHERE supplier_id=? ORDER BY id'),
            $this->rows('SELECT * FROM supplier_bank_accounts WHERE supplier_id=? ORDER BY id'),
            $this->rows('SELECT bt.* FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id WHERE bs.supplier_id=? ORDER BY bt.id'),
            $this->rows('SELECT * FROM stereo_nx_import_map WHERE supplier_id=? ORDER BY kind,source_key'),
            $this->rows('SELECT * FROM journal_entries WHERE supplier_id=? ORDER BY id'),
            $this->rows('SELECT * FROM journal_entry_lines WHERE supplier_id=? ORDER BY id')];
    }
}
