<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\AccountingPeriodProvisioner;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\ActivityLogger;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountingPeriodPostingGuardTest extends TestCase
{
    private PDO $pdo;
    private AccountingPeriodRepository $periods;
    private AccountingPeriodProvisioner $provisioner;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec("CREATE TABLE accounting_periods (
            id INTEGER PRIMARY KEY, supplier_id INTEGER, fiscal_year INTEGER, starts_on TEXT, ends_on TEXT,
            status TEXT DEFAULT 'open', closed_at TEXT, created_at TEXT, row_version INTEGER,
            closed_by INTEGER, approved_at TEXT, approved_by INTEGER, reviewed_at TEXT, reviewed_by INTEGER,
            approval_body TEXT, approval_decision_ref TEXT, approval_document_hash TEXT,
            created_reason TEXT, small_asset_accrual_mode TEXT, small_asset_accrual_pct REAL,
            UNIQUE (supplier_id, fiscal_year)
        )");
        $this->pdo->exec('CREATE TABLE accounting_supplier_settings (
            supplier_id INTEGER, small_asset_accrual_mode TEXT, small_asset_accrual_pct REAL
        )');
        $this->pdo->exec('CREATE TABLE supplier (
            id INTEGER PRIMARY KEY, accounting_mode TEXT, accounting_enabled INTEGER, accounting_starts_on TEXT
        )');
        $this->pdo->exec("INSERT INTO supplier VALUES (7, 'double_entry', 1, NULL), (8, 'double_entry', 1, NULL)");
        $this->pdo->exec('CREATE TABLE journal_entries (supplier_id INTEGER, period_id INTEGER)');
        $this->pdo->exec('CREATE TABLE activity_log (
            supplier_id INTEGER, user_id INTEGER, action TEXT, entity_type TEXT,
            entity_id INTEGER, payload TEXT, ip TEXT, user_agent TEXT
        )');
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($this->pdo);
        $this->periods = new AccountingPeriodRepository($db);
        $this->provisioner = new AccountingPeriodProvisioner($db, $this->periods, new ActivityLogger($db));
    }

    public static function rejectedDates(): array
    {
        return [
            'začátek přes provisioner' => [true, '2016-10-07', 'period_before_start'],
            'začátek přes repository' => [false, '2016-10-07', 'period_before_start'],
            'starší rok přes provisioner' => [true, '2024-12-31', 'period_before_start'],
        ];
    }

    #[DataProvider('rejectedDates')]
    public function testPostingRefusesMissingHistoricalPeriod(bool $viaProvisioner, string $date, string $code): void
    {
        $this->periods->create(7, 2026, '2026-01-01', '2026-12-31');
        $this->periods->create(7, 2028, '2028-01-01', '2028-12-31');
        $this->periods->create(8, 2010, '2010-01-01', '2010-12-31');

        try {
            $this->openForPosting($viaProvisioner, $date);
            self::fail('Zaúčtování nesmí založit chybějící historické období.');
        } catch (PostingException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
        self::assertCount(2, $this->periods->listForTenant(7));
    }

    public function testFirstAndLaterPeriodsOpenForPosting(): void
    {
        $this->periods->create(8, 2030, '2030-01-01', '2030-12-31');

        $first = $this->openForPosting(true, '2026-01-01');
        $next = $this->openForPosting(false, '2027-01-01');

        self::assertSame('2026-01-01', $first['starts_on']);
        self::assertSame('2027-12-31', $next['ends_on']);
        self::assertSame($first, $this->openForPosting(false, '2026-01-01'));
        self::assertSame($next, $this->openForPosting(true, '2027-12-31'));
        self::assertCount(2, $this->periods->listForTenant(7));
    }

    public function testPostingFillsGapAndYearBeforeFirstPeriod(): void
    {
        $this->periods->create(7, 2026, '2026-01-01', '2026-12-31');
        $this->periods->create(7, 2028, '2028-01-01', '2028-12-31');

        self::assertSame('2027-01-01', $this->openForPosting(true, '2027-06-30')['starts_on']);
        self::assertSame('2025-01-01', $this->openForPosting(false, '2025-12-31')['starts_on']);
        self::assertCount(4, $this->periods->listForTenant(7));
    }

    public function testImportMayStillOpenHistoricalPeriod(): void
    {
        $this->periods->create(7, 2026, '2026-01-01', '2026-12-31');

        $period = $this->provisioner->ensureOpenPeriodForDate(7, '2016-10-07', AccountingPeriodProvisioner::REASON_IMPORT);

        self::assertSame('2016-01-01', $period['starts_on']);
        self::assertSame('import', $period['created_reason']);
        self::assertSame($period, $this->openForPosting(true, '2016-10-07'));
    }

    public function testActivationMayPrepareEarlierPeriod(): void
    {
        $this->periods->create(7, 2026, '2026-01-01', '2026-12-31');

        $period = $this->periods->ensureOpenPeriodFor(7, '2016-10-07', forPosting: false);

        self::assertSame(2016, $period['fiscal_year']);
        self::assertNull($period['created_reason']);
        self::assertSame($period, $this->openForPosting(false, '2016-10-07'));
    }

    public function testDiagnosticUsesKnownStartAndSkipsImportsAndPeriodsWithEntries(): void
    {
        $empty = $this->periods->create(7, 2016, '2016-01-01', '2016-12-31');
        $this->periods->create(7, 2017, '2017-01-01', '2017-12-31', 'import');
        $used = $this->periods->create(7, 2018, '2018-01-01', '2018-12-31', 'posting');
        $this->pdo->prepare('INSERT INTO journal_entries VALUES (7, ?)')->execute([$used]);
        $this->periods->create(8, 2015, '2015-01-01', '2015-12-31');
        self::assertSame([], $this->periods->emptyBeforeAccountingStart(7));

        $this->periods->create(7, 2026, '2026-01-01', '2026-12-31', 'setup');
        self::assertSame([$empty], array_column($this->periods->emptyBeforeAccountingStart(7), 'id'));

        $this->pdo->exec("UPDATE supplier SET accounting_starts_on = '2016-07-01' WHERE id = 7");
        self::assertSame([], $this->periods->emptyBeforeAccountingStart(7));
        self::assertCount(4, $this->periods->listForTenant(7));
    }

    private function openForPosting(bool $viaProvisioner, string $date): ?array
    {
        return $viaProvisioner
            ? $this->provisioner->ensureOpenPeriodForDate(7, $date, AccountingPeriodProvisioner::REASON_POSTING)
            : $this->periods->ensureOpenPeriodFor(7, $date);
    }
}
