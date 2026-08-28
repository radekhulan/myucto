<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll\Posting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollPostingBatchRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ú-15 — zamčené nebo uzavřené období posouvá datum mzdového zápisu na první
 * otevřené. Uvnitř jednoho účetního roku je to jen volba dne. PŘES HRANICI ROKU
 * je to ale přesun nákladu do období, se kterým věcně ani časově nesouvisí
 * (§ 3 odst. 1 ZoÚ) — a to se dosud dělo TIŠE.
 */
#[Group('integration')]
final class PayrollPostingEntryDateFiscalYearTest extends TestCase
{
    private const YEAR = 2097;

    private Connection $db;
    private PayrollPostingBatchRepository $batches;
    private int $supplierId = 0;
    private bool $inTransaction = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->batches = $container->get(PayrollPostingBatchRepository::class);
        } catch (\Throwable $exception) {
            $this->markTestSkipped('DI nedostupné: ' . $exception->getMessage());
        }

        $pdo = $this->db->pdo();
        $currencyId = (int) ($pdo->query(
            "SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1",
        )->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query(
            'SELECT id FROM vat_rates ORDER BY id LIMIT 1',
        )->fetchColumn() ?: 0);
        $countryId = (int) ($pdo->query(
            "SELECT id FROM countries WHERE iso2 = 'CZ' ORDER BY id LIMIT 1",
        )->fetchColumn() ?: 0);
        if ($currencyId === 0 || $vatRateId === 0 || $countryId === 0) {
            $this->markTestSkipped('Chybí základní data (currency/vat_rate/country).');
        }

        $pdo->beginTransaction();
        $this->inTransaction = true;
        $statement = $pdo->prepare(
            'INSERT INTO supplier
                (company_name, street, city, zip, country_id, email,
                 default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?, "double_entry")',
        );
        $statement->execute([
            'Payroll Entry Date ' . bin2hex(random_bytes(4)) . ' s.r.o.',
            $countryId,
            'payroll-entry-date-' . bin2hex(random_bytes(4)) . '@example.com',
            $currencyId,
            $vatRateId,
        ]);
        $this->supplierId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || !$this->inTransaction) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $this->db->close();
    }

    public function testRefusesToPushPayrollExpenseIntoTheNextFiscalYear(): void
    {
        $this->createPeriod(self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31', 'closed');
        $this->createPeriod(
            self::YEAR + 1,
            (self::YEAR + 1) . '-01-01',
            (self::YEAR + 1) . '-12-31',
            'open',
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('jiného účetního roku');
        $this->batches->resolveEntryDate($this->supplierId, self::YEAR . '-12-31');
    }

    /** Posun UVNITŘ roku je legitimní — mění se den, ne účetní období. */
    public function testShiftInsideTheSameFiscalYearIsStillAllowed(): void
    {
        $this->createPeriod(self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31', 'open');
        $this->lockUntil(self::YEAR . '-07-31');

        self::assertSame(
            self::YEAR . '-08-01',
            $this->batches->resolveEntryDate($this->supplierId, self::YEAR . '-06-30'),
        );
    }

    /** Nezamčené období se neposouvá vůbec. */
    public function testUnlockedPeriodKeepsThePayrollPeriodEnd(): void
    {
        $this->createPeriod(self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31', 'open');

        self::assertSame(
            self::YEAR . '-06-30',
            $this->batches->resolveEntryDate($this->supplierId, self::YEAR . '-06-30'),
        );
    }

    private function createPeriod(
        int $fiscalYear,
        string $startsOn,
        string $endsOn,
        string $status,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO accounting_periods
                (supplier_id, fiscal_year, starts_on, ends_on, status)
             VALUES (?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $this->supplierId,
            $fiscalYear,
            $startsOn,
            $endsOn,
            $status,
        ]);
    }

    private function lockUntil(string $lockedUntil): void
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO accounting_supplier_settings (supplier_id, locked_until)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)',
        );
        $statement->execute([$this->supplierId, $lockedUntil]);
    }
}
