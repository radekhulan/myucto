<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AssetRepository;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\Card\CardClearingRegime;
use MyInvoice\Service\Accounting\Card\CardPaymentAutomation;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Accounting\Payroll\PayrollPostingService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Payroll\PayrollPeriodOwnedException;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Tests\Support\AbortedTransactionTrait;
use MyInvoice\Tests\Support\ScriptedFailurePdo;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Vlastníci transakcí, jejichž chybu volající polyká (kartová automatika, cron mezd,
 * hromadné odpisy), musí odvolanou transakci (1020/1213) zopakovat. Uvnitř cizí
 * transakce ji nesmí spolknout, další zápisy by běžely v autocommitu.
 */
final class PostingOwnersRetryTest extends TestCase
{
    use AbortedTransactionTrait;

    public function testCardAutomationRetriesAbortedRun(): void
    {
        $pdo = new ScriptedFailurePdo();
        $pdo->exec('CREATE TABLE purchase_invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER, status TEXT)');
        $pdo->exec('CREATE TABLE journal_entries (id INTEGER PRIMARY KEY, supplier_id INTEGER, source_type TEXT, source_id INTEGER, posted_at TEXT, reversed_by INTEGER)');
        $pdo->failOn('FROM purchase_invoices pi', self::abortedFailure(1213));
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $regime = $this->createStub(CardClearingRegime::class);
        $regime->method('settings')->willReturn(['enabled' => true]);
        $automation = self::serviceWith(CardPaymentAutomation::class, ['db' => $db, 'regime' => $regime]);

        self::assertSame(
            ['matched_transaction_id' => null, 'settlements' => 0],
            $automation->afterPurchaseReady(3, 9),
        );
        self::assertSame(2, $pdo->calls['FROM purchase_invoices pi']);
    }

    public function testPayrollPostingRetriesAbortedTransaction(): void
    {
        [$db, $pdo] = $this->transactionalConnection();
        $ownership = $this->createMock(PayrollPeriodOwnershipService::class);
        $ownership->expects(self::exactly(2))->method('claimLegacy')->willReturnCallback(self::abortingThen(
            static fn (): never => throw new PayrollPeriodOwnedException('Období zpracovávají mzdy.'),
        ));
        $posting = self::serviceWith(PayrollPostingService::class, ['db' => $db, 'periodOwnership' => $ownership]);

        try {
            $posting->post(3, 2099, 6, 30000.0, 'employee');
            self::fail('Druhý pokus má narazit na vlastnictví období.');
        } catch (PostingException $e) {
            self::assertSame('payroll_period_owned', $e->errorCode);
        }
        self::assertFalse($pdo->inTransaction());
    }

    public function testDepreciationRetriesEachAssetItOwns(): void
    {
        [$service, $pdo, $assets] = $this->depreciation();
        $assets->expects(self::exactly(2))->method('findForUpdate')
            ->willReturnCallback(self::abortingThen(static fn (): ?array => null));

        $result = $service->bookYear(3, 2099);

        self::assertSame([['asset_id' => 5, 'code' => 'not_found']], $result['errors']);
        self::assertFalse($pdo->inTransaction());
    }

    public function testDepreciationInsideClosingTransactionStopsOnAbort(): void
    {
        [$service, $pdo, $assets] = $this->depreciation();
        $assets->expects(self::once())->method('findForUpdate')->willThrowException(self::abortedFailure(1020));
        $pdo->beginTransaction();

        try {
            $service->bookYear(3, 2099, [], true);
            self::fail('Odvolanou transakci uzávěrky musí dostat její vlastník.');
        } catch (PDOException $e) {
            self::assertSame(1020, $e->errorInfo[1]);
        }
    }

    /** @return array{0:DepreciationPostingService,1:\PDO,2:AssetRepository&\PHPUnit\Framework\MockObject\MockObject} */
    private function depreciation(): array
    {
        $pdo = new class('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]) extends \Pdo\Sqlite {
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                return parent::prepare(preg_replace('/\s+FOR UPDATE\s*$/i', '', $query), $options);
            }
        };
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $pdo->exec('CREATE TABLE accounting_periods (id INTEGER PRIMARY KEY, supplier_id INTEGER, status TEXT, starts_on TEXT)');
        $pdo->exec("INSERT INTO accounting_periods VALUES (1, 3, 'open', '2099-01-01')");
        $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY, accounting_mode TEXT)');
        $pdo->exec("INSERT INTO supplier VALUES (3, 'double_entry')");
        $periods = $this->createStub(AccountingPeriodRepository::class);
        $periods->method('fiscalCalendar')->willReturn(FiscalCalendar::calendar());
        $periods->method('findByYear')->willReturn(['starts_on' => '2099-01-01', 'ends_on' => '2099-12-31']);
        $assets = $this->createMock(AssetRepository::class);
        $assets->method('listForBooking')->willReturn([['id' => 5]]);
        $service = self::serviceWith(DepreciationPostingService::class, [
            'db' => $db,
            'periods' => $periods,
            'assets' => $assets,
            'logger' => new NullLogger(),
        ]);

        return [$service, $pdo, $assets];
    }
}
