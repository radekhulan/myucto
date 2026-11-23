<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Report\CrossCheckSuite;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kontrola „VZZ = obratová předvaha" musí brát skladové sloty kroku „Zásoby" stejně
 * jako výsledovka: zápis nese source_type 'closing', ale je reálnou změnou stavu zásob.
 * Dřív hlavní kniha vylučovala každý 'closing' zápis a kontrola u firmy se zásobami
 * hlásila rozdíl ve výši změny stavu zásob.
 */
#[Group('integration')]
final class CrossCheckStockSlotTest extends TestCase
{
    private \PDO $pdo;
    private int $supplier;
    private int $period;
    /** @var array<string,int> */
    private array $accounts = [];

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->pdo = $container->get(Connection::class)->pdo();
        $this->pdo->beginTransaction();
        $this->pdo->exec("INSERT INTO supplier (company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id)
            SELECT 'Synthetic stock cross-check','Test 1','Test','10000','crosscheck-stock@example.com',
                (SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1),
                (SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1),
                (SELECT id FROM vat_rates ORDER BY id LIMIT 1)");
        $this->supplier = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplier]);
        $this->period = $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2025, '2025-01-01', '2025-12-31');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?, '2025-01-01', 'cz_500')")
            ->execute([$this->supplier]);
        foreach (['112' => 'asset', '221' => 'asset', '701' => 'equity', '710' => 'equity', '411' => 'equity',
            '501' => 'expense', '518' => 'expense', '604' => 'revenue'] as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts (supplier_id,account_code,name,account_type) VALUES (?,?,?,?)')
                ->execute([$this->supplier, (string) $code, 'Synthetic ' . $code, $type]);
            $this->accounts[(string) $code] = (int) $this->pdo->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function post(string $date, string $debit, string $credit, float $amount, string $source = 'manual', ?int $sourceId = null): void
    {
        $this->pdo->prepare('INSERT INTO journal_entries (supplier_id,period_id,entry_date,source_type,source_id) VALUES (?,?,?,?,?)')
            ->execute([$this->supplier, $this->period, $date, $source, $sourceId]);
        $entry = (int) $this->pdo->lastInsertId();
        foreach (['debit' => $debit, 'credit' => $credit] as $side => $code) {
            $this->pdo->prepare('INSERT INTO journal_entry_lines (supplier_id,entry_id,account_id,side,amount) VALUES (?,?,?,?,?)')
                ->execute([$this->supplier, $entry, $this->accounts[$code], $side, $amount]);
        }
        $this->pdo->prepare('UPDATE journal_entries SET posted_at = NOW() WHERE id = ?')->execute([$entry]);
    }

    public function testIncomeStatementVsLedgerKeepsStockSlot(): void
    {
        $this->post('2025-01-01', '221', '411', 1000, 'opening');
        $this->post('2025-03-01', '501', '221', 600);
        $this->post('2025-05-01', '221', '604', 900);
        // Konečný stav zásob k rozvahovému dni: MD 112 / D 501 (skladový slot).
        $this->post('2025-12-31', '112', '501', 250, 'closing', ClosingSourceId::stockClosing($this->period));
        $this->pdo->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->period]);

        $suite = Bootstrap::buildContainer()->get(CrossCheckSuite::class);
        $checks = array_column($suite->run($this->supplier, 2025), null, 'check');
        $check = $checks['income_statement_vs_ledger'];

        self::assertFalse($check['skipped']);
        self::assertSame(550.0, $check['a'], 'VZZ: 900 − (600 − 250).');
        self::assertSame(550.0, $check['b'], 'Hlavní kniha musí skladový slot započítat stejně jako VZZ.');
        self::assertTrue($check['ok']);
    }
}
