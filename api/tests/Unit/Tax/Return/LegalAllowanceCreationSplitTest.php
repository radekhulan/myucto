<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\Return\LegalProvisionLedgerService;
use PHPUnit\Framework\TestCase;

final class LegalAllowanceCreationSplitTest extends TestCase
{
    private \Pdo\Sqlite $pdo;
    private LegalProvisionLedgerService $service;

    protected function setUp(): void
    {
        $this->pdo = new \Pdo\Sqlite('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->createFunction('CONCAT', static fn (...$parts): string => implode('', $parts));
        $this->pdo->exec("CREATE TABLE journal_entries (id INTEGER PRIMARY KEY, supplier_id INTEGER, period_id INTEGER, source_type TEXT, source_id INTEGER, reversed_by INTEGER, entry_date TEXT, posted_at TEXT);
            CREATE TABLE journal_entry_lines (entry_id INTEGER, supplier_id INTEGER, account_id INTEGER, side TEXT, amount REAL);
            CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, account_code TEXT, account_type TEXT, tax_deductibility TEXT, parent_id INTEGER);
            CREATE TABLE accounting_closing_steps (supplier_id INTEGER, period_id INTEGER, step_key TEXT, payload TEXT);
            INSERT INTO chart_of_accounts VALUES (1,'558','expense','deductible',NULL),(2,'391','asset','deductible',NULL),(3,'701','closing','deductible',NULL);
            INSERT INTO journal_entries VALUES (10,1,2025,'provision',777,NULL,'2025-12-31','2025-12-31'),(20,1,2026,'opening',2026,NULL,'2026-01-01','2026-01-01');
            INSERT INTO journal_entry_lines VALUES (10,1,1,'debit',10000),(10,1,2,'credit',10000),(20,1,3,'debit',10000),(20,1,2,'credit',10000);");
        $db = new Connection(new Config([]));
        (new \ReflectionProperty($db, 'pdo'))->setValue($db, $this->pdo);
        $this->service = new LegalProvisionLedgerService($db);
    }

    public function testCarryIncreaseExportsOnlyCurrentCreationAndFullClosingBalance(): void
    {
        $this->movement(30, 5000, true);
        $this->declaration(30, 15000);
        $data = $this->service->forPeriod(1, 2026, '2026-01-01', '2026-12-31');
        self::assertSame(5000.0, $data['legal_allowance_created']);
        self::assertSame(15000.0, $data['allowance_balance']);
        self::assertTrue($data['allowance_created_split_reliable']);
        self::assertSame(5000.0, $data['allowance_created_by_section']['8a']);
        $xml = $this->xml($data);
        self::assertStringContainsString('kc_dpp_c6="5000"', $xml);
        self::assertStringContainsString('kc_dpp_c7="15000"', $xml);
    }

    public function testReleaseDoesNotBecomeCreationOrReusePriorYearAmount(): void
    {
        $this->movement(30, 3000, false);
        $this->declaration(30, 7000);
        $data = $this->service->forPeriod(1, 2026, '2026-01-01', '2026-12-31');
        self::assertSame(0.0, $data['legal_allowance_created']);
        self::assertTrue($data['allowance_created_split_reliable']);
        self::assertSame(0.0, $data['allowance_created_by_section']['8a']);
        $xml = $this->xml($data);
        self::assertStringNotContainsString('kc_dpp_c6=', $xml);
        self::assertStringContainsString('kc_dpp_c7="7000"', $xml);
    }

    public function testUnmappedCreationCannotBeHiddenByMatchingDeclaredBalance(): void
    {
        $this->movement(30, 3000, true);
        $this->movement(31, 2000, true);
        $this->declaration(30, 15000);
        $data = $this->service->forPeriod(1, 2026, '2026-01-01', '2026-12-31');
        self::assertTrue($data['allowance_split_reliable']);
        self::assertFalse($data['allowance_created_split_reliable']);
        self::assertSame(3000.0, $data['allowance_created_by_section']['8a']);
        self::assertStringNotContainsString('kc_dpp_c6=', $this->xml($data));
    }

    public function testReversalOfCurrentCreationIsNotDeclaredTwice(): void
    {
        $this->movement(30, 5000, true);
        $this->movement(31, 5000, false);
        $this->movement(32, 5000, true);
        $this->pdo->exec('UPDATE journal_entries SET reversed_by = 31 WHERE id = 30; UPDATE journal_entries SET reversed_by = 32, source_id = NULL WHERE id = 31; UPDATE journal_entries SET source_id = NULL WHERE id = 32');
        $this->declaration(30, 15000);
        $data = $this->service->forPeriod(1, 2026, '2026-01-01', '2026-12-31');
        self::assertTrue($data['allowance_created_split_reliable']);
        self::assertSame(5000.0, $data['allowance_created_by_section']['8a']);
    }

    private function movement(int $id, float $amount, bool $create): void
    {
        $this->pdo->prepare("INSERT INTO journal_entries VALUES (?,1,2026,'provision',?,NULL,'2026-12-31','2026-12-31')")->execute([$id, $id + 777]);
        $stmt = $this->pdo->prepare('INSERT INTO journal_entry_lines VALUES (?,1,?,?,?)');
        $stmt->execute([$id, 1, $create ? 'debit' : 'credit', $amount]);
        $stmt->execute([$id, 2, $create ? 'credit' : 'debit', $amount]);
    }

    private function declaration(?int $entryId, float $balance): void
    {
        $this->pdo->prepare("INSERT INTO accounting_closing_steps VALUES (1,2026,'provisions',?)")->execute([json_encode(['entries' => [
            ['invoice_id' => 777, 'entry_id' => $entryId, 'legal_amount' => $balance, 'acct_amount' => 0, 'legal_section' => '8a'],
        ]])]);
    }

    private function xml(array $data): string
    {
        $calc = (new DppoReturnCalculator())->compute(['legal_provisions' => $data], [], []);
        return (new DppoXmlBuilder())->build(['company_name' => 'Test', 'taxpayer_type' => 'po', 'financial_office_code' => '451'], 2026, $calc)['xml'];
    }
}
