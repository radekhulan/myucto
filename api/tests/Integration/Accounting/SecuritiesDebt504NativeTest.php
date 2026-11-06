<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Tests\Unit\Accounting\Closing\SecuritiesDebt504BasisTest;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SecuritiesValuation504ReadRepository;
use MyInvoice\Service\Accounting\Closing\SecuritiesDebt504BasisReader;
use PDO;
use PHPUnit\Framework\TestCase;

final class SecuritiesDebt504NativeTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private int $supplier;
    private array $period;
    private array $accounts = [];

    protected function setUp(): void
    {
        $name = getenv('MYINVOICE_DB_NAME');
        if (!is_string($name) || preg_match('/^[a-zA-Z0-9_]+_test$/D', $name) !== 1) throw new \RuntimeException('Owned native database required.');
        $this->db = new Connection(Config::load(Bootstrap::rootDir()));
        $this->pdo = $this->db->pdo();
        self::assertSame($name, $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->pdo->beginTransaction();
        $this->pdo->exec("INSERT IGNORE INTO countries(iso2,iso3,name_cs,name_en) VALUES ('CZ','CZE','Synthetic CZ','Synthetic CZ')");
        $this->pdo->exec("INSERT INTO vat_rates(code,rate_percent,label_cs,label_en,valid_from) VALUES ('SYN-DEBT',0,'Synthetic','Synthetic','2026-01-01')");
        $vatId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $this->pdo->prepare("INSERT INTO supplier (company_name,street,city,zip,email,country_id,default_currency_id,default_vat_rate_id) SELECT 'Synthetic debt reader','Test','Test','10000','synthetic@example.invalid',(SELECT id FROM countries WHERE iso2='CZ' LIMIT 1),1,?")->execute([$vatId]);
            $this->supplier = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en) VALUES (?,'CZK','Synthetic','Kc','Synthetic','Synthetic')")->execute([$this->supplier]);
            $currencyId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([$currencyId, $this->supplier]);
        } finally { $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1'); }
        $this->pdo->prepare("INSERT INTO accounting_periods(supplier_id,fiscal_year,starts_on,ends_on,status) VALUES (?,2026,'2026-01-01','2026-01-20','open')")->execute([$this->supplier]);
        $this->period = ['id' => (int) $this->pdo->lastInsertId(), 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-20', 'fiscal_year' => 2026, 'status' => 'open'];
        foreach (['253' => 'asset', '221' => 'asset', '644' => 'revenue'] as $code => $type) {
            $this->pdo->prepare('INSERT INTO chart_of_accounts(supplier_id,account_code,name,account_type) VALUES (?,?,?,?)')->execute([$this->supplier, (string) $code, 'Synthetic ' . $code, $type]);
            $this->accounts[$code] = (int) $this->pdo->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testActualCurrentReaderDirtyAcquisitionPartialCouponAndReversal(): void
    {
        $acquisition = $this->entry('1010.00', 'debit', '221', '2026-01-11');
        $accrual = $this->entry('10.00', 'debit', '644', '2026-01-20');
        $coupon = $this->entry('7.00', 'credit', '221', '2026-01-20');
        $inventory = new SecuritiesValuation504ReadRepository($this->db);
        $snapshot = SecuritiesValuation504ReadRepository::select($inventory->snapshot($this->supplier, $this->period), []);
        $request = $this->request($snapshot, $acquisition, $accrual, $coupon);
        $reader = new SecuritiesDebt504BasisReader($inventory);
        $before = $reader->read($this->supplier, $this->period, $request);
        self::assertSame('13.00', $before['lots'][0]['unpaid_interest_candidate']);
        self::assertSame('10.00', $before['lots'][0]['current_earned']);
        self::assertContains('debt_payment_lineage_review_required', array_column($before['findings'], 'code'));
        self::assertFalse($before['financial_authorization']);
        $reverse = $this->entry('7.00', 'debit', '221', '2026-01-20');
        $this->pdo->prepare('UPDATE journal_entries SET reversed_by=?,row_version=row_version+1 WHERE id=? AND supplier_id=?')->execute([$reverse, $coupon, $this->supplier]);
        $stale = $reader->read($this->supplier, $this->period, $request, true);
        self::assertContains('debt_inventory_changed', array_column($stale['findings'], 'code'));
        $snapshot = SecuritiesValuation504ReadRepository::select($inventory->snapshot($this->supplier, $this->period, true), []);
        self::assertContains($coupon, $snapshot['cancelled_entry_ids']);
        self::assertContains($reverse, $snapshot['cancelled_entry_ids']);
        $request = $this->request($snapshot, $acquisition, $accrual, null);
        $after = $reader->read($this->supplier, $this->period, $request, true);
        self::assertSame('20.00', $after['lots'][0]['unpaid_interest_candidate']);
        self::assertTrue($after['ledger_complete']);
        self::assertTrue($this->pdo->inTransaction());
    }

    public function testPhysicalZeroNetMembershipAndCallerTransactionArePreserved(): void
    {
        $acquisition = $this->entry('1010.00', 'debit', '221', '2026-01-11');
        $accrual = $this->entry('10.00', 'debit', '644', '2026-01-20');
        $inventory = new SecuritiesValuation504ReadRepository($this->db);
        $snapshot = SecuritiesValuation504ReadRepository::select($inventory->snapshot($this->supplier, $this->period), []);
        $request = $this->request($snapshot, $acquisition, $accrual, null);
        $this->entry('3.00', 'debit', '221', '2026-01-20');
        $this->entry('3.00', 'credit', '221', '2026-01-20');
        $current = SecuritiesValuation504ReadRepository::select($inventory->snapshot($this->supplier, $this->period, true), []);
        self::assertCount(count($snapshot['cells']) + 2, $current['cells']);
        self::assertNotSame($snapshot['hash'], $current['hash']);
        $request['inventory_hash'] = $current['hash'];
        $result = SecuritiesDebt504BasisReader::inspect($current, $request, $this->period['starts_on']);
        self::assertContains('debt_source_allocation_incomplete', array_column($result['findings'], 'code'));
        self::assertTrue($this->pdo->inTransaction());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM supplier WHERE id=' . $this->supplier)->fetchColumn());
    }

    private function entry(string $amount, string $side, string $counterpart, string $date): int
    {
        $this->pdo->prepare("INSERT INTO journal_entries(supplier_id,period_id,entry_date,source_type,posted_at) VALUES (?,?,?,'manual',?)")->execute([$this->supplier, $this->period['id'], $date, $date . ' 12:00:00']);
        $id = (int) $this->pdo->lastInsertId();
        foreach ([['253', $side], [$counterpart, $side === 'debit' ? 'credit' : 'debit']] as $index => [$code, $lineSide]) {
            $this->pdo->prepare('INSERT INTO journal_entry_lines(supplier_id,entry_id,line_no,account_id,side,amount) VALUES (?,?,?,?,?,?)')->execute([$this->supplier, $id, $index + 1, $this->accounts[$code], $lineSide, $amount]);
        }
        return $id;
    }

    private function request(array $snapshot, int $acquisition, int $accrual, ?int $coupon): array
    {
        [, $request] = SecuritiesDebt504BasisTest::fixture();
        $request['inventory_hash'] = $snapshot['hash'];
        $lot = &$request['lots'][0];
        $asset = array_values(array_filter($snapshot['cells'], static fn ($cell) => $cell['entry_id'] === $acquisition))[0];
        foreach ($lot['acquisition'] as &$allocation) { $allocation['cell_key'] = $asset['cell_key']; $allocation['source_hash'] = $asset['source_hash']; }
        unset($allocation);
        $lot['events'] = [];
        foreach ([$accrual => 'accrual'] + ($coupon === null ? [] : [$coupon => 'coupon_receipt']) as $entryId => $kind) {
            $allocations = [];
            foreach (SecuritiesValuation504ReadRepository::cells($snapshot['entries'][$entryId]['lines']) as $cell) $allocations[] = ['cell_key' => $cell['cell_key'], 'source_hash' => $cell['source_hash'], 'component' => str_starts_with($cell['account_code'], '253') ? 'asset' : 'counterpart', 'czk' => $cell['book_czk'], 'foreign' => $cell['book_foreign']];
            $lot['events'][] = ['entry_id' => $entryId, 'kind' => $kind, 'allocations' => $allocations];
        }
        return $request;
    }
}
