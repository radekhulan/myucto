<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\TestCase;

final class PropertyClosingReconciliationTest extends TestCase
{
    use ChargeIssuanceFixture;
    private Connection $db;
    private array $f;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test', (string) getenv('MYINVOICE_DB_NAME'));
        self::assertSame(getenv('MYINVOICE_DB_NAME'), $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();
        $this->f = $this->issuanceFixture($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testMonthlyAndSelectivePrecheckDetectCurrentMismatchAtTheirActualEndDate(): void
    {
        $container = $this->container();
        $supplier = $this->f['supplier'];
        $period = $container->get(AccountingPeriodRepository::class)->findByYear($supplier, 2026);
        $closing = $container->get(ClosingService::class);
        $container->get(PostingService::class)->postDocument($supplier, 'manual', null, [
            ['account_code' => '315', 'side' => 'debit', 'amount' => '100.00'],
            ['account_code' => '324', 'side' => 'credit', 'amount' => '100.00'],
        ], ['entry_date' => '2026-03-05', 'posted_by' => $this->f['actor'], 'description' => 'Synthetic unmatched receivable']);
        foreach (['2026-02-28' => true, '2026-03-31' => false] as $date => $matches) {
            $checks = $closing->buildChecks($supplier, $period, '2026-01-01', $date, onlyKeys: ['property_reconciliation']);
            self::assertCount(1, $checks);
            self::assertSame('error', $checks[0]['severity']);
            self::assertSame($matches, $checks[0]['ok']);
            self::assertSame($date, $checks[0]['value']['accounting_date']);
            self::assertSame($matches ? '0' : '10000', $checks[0]['value']['difference_cents']['receivable']);
            $month = $closing->monthlyCheck($supplier, $period['id'], substr($date, 0, 7).'-01', $date);
            $monthly = array_values(array_filter($month['checks'], fn (array $check): bool => $check['key'] === 'property_reconciliation'));
            self::assertSame($checks, $monthly);
        }
        $errors = (new \ReflectionMethod($closing, 'failingErrorChecks'))->invoke($closing, $supplier, $period);
        self::assertContains('property_reconciliation', $errors);
    }

    public function testFramework500DoesNotAcquireAnUnrelatedClosingGate(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework='cz_500' WHERE supplier_id=?")->execute([$this->f['supplier']]);
        $container = $this->container();
        $period = $container->get(AccountingPeriodRepository::class)->findByYear($this->f['supplier'], 2026);
        self::assertSame([], $container->get(ClosingService::class)->buildChecks($this->f['supplier'], $period, onlyKeys: ['property_reconciliation']));
    }

    private function container(): \DI\Container
    {
        $container = Bootstrap::buildContainer();
        $container->set(Connection::class, $this->db);
        return $container;
    }
}
