<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingPeriodRepository, ClosingRepository, JournalEntryRepository};
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\{ClosingException, ClosingService, ClosingSourceId};
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * První rok v MyÚčtu po převodu z jiného systému: výsledek minulého roku přišel na 431
 * převzatou otevírací rozvahou a předchozí období v MyÚčtu není. Zahájení uzávěrky
 * nerozdělený výsledek odmítne (profit_not_distributed), takže ho musí jít rozdělit
 * průvodcem proti počátečnímu stavu 431, ne jen ručním zápisem.
 */
#[Group('integration')]
final class ClosingProfitDistributionOpeningTest extends TestCase
{
    private const YEAR = 2095;

    private Connection $db;
    private ClosingService $closing;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;
    private int $supplier;
    private int $period;
    private int $userId;

    protected function setUp(): void
    {
        $c = Bootstrap::buildContainer();
        $this->db = $c->get(Connection::class);
        $this->closing = $c->get(ClosingService::class);
        $this->posting = $c->get(PostingService::class);
        $this->periods = $c->get(AccountingPeriodRepository::class);
        $pdo = $this->db->pdo();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO supplier(company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id,accounting_mode)
            SELECT 'Prvni rok po prevodu','Test 1','Test','10000','opening-distribution@example.com',country_id,default_currency_id,default_vat_rate_id,'double_entry' FROM supplier ORDER BY id LIMIT 1");
        $this->supplier = (int) $pdo->lastInsertId();
        $c->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplier);
        $this->period = $this->periods->create($this->supplier, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testFirstMigratedYearDistributesProfitFromOpeningAndStartsClosing(): void
    {
        $this->openingWith431(-80000.00);
        try {
            $this->closing->start($this->supplier, $this->period, $this->rv(), $this->meta());
            self::fail('Nerozdělený výsledek z počátečních stavů musí zahájení zastavit.');
        } catch (ClosingException $e) {
            self::assertSame('profit_not_distributed', $e->errorCode);
        }

        $preview = $this->closing->profitDistributionPreview($this->supplier, $this->period);
        self::assertSame('opening', $preview['source']);
        self::assertNull($preview['approved_period']);
        self::assertSame(self::YEAR - 1, $preview['source_fiscal_year']);
        self::assertSame($this->period, $preview['target_period']['id']);
        self::assertSame(80000.0, $preview['available_profit']);

        $result = $this->closing->runProfitDistribution($this->supplier, $this->period, [
            'decision_date' => self::YEAR . '-03-15',
            'target_row_version' => $preview['target_period']['row_version'],
            'allocations' => [['account_code' => '428', 'amount' => 80000.00, 'kind' => 'retained']],
        ], $this->meta());

        $entry = Bootstrap::buildContainer()->get(JournalEntryRepository::class)
            ->findBySource($this->supplier, 'profit_distribution', ClosingSourceId::openingProfitDistribution($this->period));
        self::assertSame($result['entry_id'], (int) $entry['id']);
        self::assertStringContainsString((string) (self::YEAR - 1), (string) $entry['description']);
        $repo = Bootstrap::buildContainer()->get(ClosingRepository::class);
        self::assertSame(0.0, $repo->accountBalance($this->supplier, '431', self::YEAR . '-12-31'));
        self::assertSame(-80000.0, $repo->accountBalance($this->supplier, '428', self::YEAR . '-12-31'));
        self::assertSame($result['entry_id'], $this->closing->profitDistributionPreview($this->supplier, $this->period)['existing_entry_id']);

        $started = $this->closing->start($this->supplier, $this->period, $this->rv(), $this->meta());
        self::assertSame('closing', $started['status']);
    }

    public function testOpeningDistributionCanBeRevertedAndKeepsKeyOfNextYearsDistributionFree(): void
    {
        $this->openingWith431(-500.00);
        $preview = $this->closing->profitDistributionPreview($this->supplier, $this->period);
        $this->closing->runProfitDistribution($this->supplier, $this->period, [
            'decision_date' => self::YEAR . '-03-15',
            'target_row_version' => $preview['target_period']['row_version'],
            'allocations' => [['account_code' => '428', 'amount' => 500.00, 'kind' => 'retained']],
        ], $this->meta());
        $journal = Bootstrap::buildContainer()->get(JournalEntryRepository::class);
        self::assertNull($journal->findBySource($this->supplier, 'profit_distribution', $this->period), 'Klíč rozdělení výsledku tohoto roku zůstává volný.');

        $this->closing->revertProfitDistribution($this->supplier, $this->period, $this->rv(), $this->meta());
        self::assertNull($journal->findBySource($this->supplier, 'profit_distribution', ClosingSourceId::openingProfitDistribution($this->period)));
        self::assertSame(500.0, $this->closing->profitDistributionPreview($this->supplier, $this->period)['available_profit']);
    }

    public function testYearWithoutOpeningStillRequiresApprovedPreviousPeriod(): void
    {
        try {
            $this->closing->profitDistributionPreview($this->supplier, $this->period);
            self::fail('Bez předchozího období a bez počátečních stavů není co rozdělit.');
        } catch (ClosingException $e) {
            self::assertSame('profit_distribution_requires_approved_period', $e->errorCode);
        }
    }

    /** Převzatá otevírací rozvaha s výsledkem minulého roku na 431 (záporně = zisk). */
    private function openingWith431(float $balance431): void
    {
        $amount = abs($balance431);
        $this->posting->postDocument($this->supplier, 'opening', null, [
            ['account_code' => '221', 'side' => $balance431 < 0 ? 'debit' : 'credit', 'amount' => $amount],
            ['account_code' => '431', 'side' => $balance431 < 0 ? 'credit' : 'debit', 'amount' => $amount],
        ], ['entry_date' => self::YEAR . '-01-01', 'posted' => true, 'document_no' => 'PS-' . self::YEAR] + $this->meta());
    }

    private function rv(): int
    {
        return (int) $this->periods->findById($this->supplier, $this->period)['row_version'];
    }

    /** @return array{user_id:int, posted_by:int} */
    private function meta(): array
    {
        return ['user_id' => $this->userId, 'posted_by' => $this->userId];
    }
}
