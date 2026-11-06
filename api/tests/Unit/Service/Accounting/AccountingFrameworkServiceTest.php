<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkException;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use PHPUnit\Framework\TestCase;

final class AccountingFrameworkServiceTest extends TestCase
{
    public function testMissingHistoryFallsBackToCz500AndKeepsTenantScope(): void
    {
        $repository = $this->createMock(AccountingFrameworkRepository::class);
        $repository->expects(self::once())
            ->method('forDate')
            ->with(37, '2026-12-31')
            ->willReturn(null);

        $service = new AccountingFrameworkService(
            $this->createStub(Connection::class),
            $repository,
        );

        self::assertSame(AccountingFrameworkService::CZ_500, $service->forDate(37, '2026-12-31'));
    }

    public function testHistoricalCz504RowRemainsReadable(): void
    {
        $repository = $this->createStub(AccountingFrameworkRepository::class);
        $repository->method('forDate')->willReturn(AccountingFrameworkService::CZ_504);
        $service = new AccountingFrameworkService($this->createStub(Connection::class), $repository);

        self::assertSame(AccountingFrameworkService::CZ_504, $service->forDate(9, '2030-01-01'));
    }

    public function testHistoryIsTenantScopedAndHidesRepositoryTenantColumn(): void
    {
        $repository = $this->createMock(AccountingFrameworkRepository::class);
        $repository->expects(self::once())->method('listForTenant')->with(9)->willReturn([[
            'id' => 17,
            'supplier_id' => 9,
            'effective_from' => '2026-01-01',
            'accounting_framework' => AccountingFrameworkService::CZ_500,
            'created_at' => '2026-01-01 10:00:00',
            'created_by' => 4,
        ]]);
        $service = new AccountingFrameworkService($this->createStub(Connection::class), $repository);

        self::assertSame([[
            'id' => 17,
            'effective_from' => '2026-01-01',
            'accounting_framework' => AccountingFrameworkService::CZ_500,
            'created_at' => '2026-01-01 10:00:00',
            'created_by' => 4,
        ]], $service->history(9));
    }

    public function testUnknownFrameworkIsRejectedBeforePersistence(): void
    {
        $repository = $this->createMock(AccountingFrameworkRepository::class);
        $repository->expects(self::never())->method('periodsOverlappingForUpdate');
        $service = new AccountingFrameworkService($this->createStub(Connection::class), $repository);

        try {
            $service->changeFromPeriodStart(9, '2030-01-01', 'cz_999');
            self::fail('Neznámý rámec měl být odmítnut.');
        } catch (AccountingFrameworkException $e) {
            self::assertSame('invalid_accounting_framework', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }

    public function testInvalidCalendarDateIsRejected(): void
    {
        $service = new AccountingFrameworkService(
            $this->createStub(Connection::class),
            $this->createStub(AccountingFrameworkRepository::class),
        );

        try {
            $service->forDate(9, '2026-02-30');
            self::fail('Neplatné datum mělo být odmítnuto.');
        } catch (AccountingFrameworkException $e) {
            self::assertSame('invalid_accounting_framework_date', $e->errorCode);
        }
    }

    public function testBatchExplicitlyFallsBackForMissingRequestedHistory(): void
    {
        $repository = $this->createMock(AccountingFrameworkRepository::class);
        $repository->expects(self::once())->method('forDateForSuppliers')->with([11, 22], '2098-01-01')->willReturn([11 => 'cz_504']);
        $service = new AccountingFrameworkService($this->createStub(Connection::class), $repository);
        self::assertSame([11 => 'cz_504', 22 => 'cz_500'], $service->forDateForSuppliers([11, 22, 11, 0], '2098-01-01'));
    }

    public function testBatchValidatesDateBeforeReadingHistory(): void
    {
        $repository = $this->createMock(AccountingFrameworkRepository::class);
        $repository->expects(self::never())->method('forDateForSuppliers');
        $service = new AccountingFrameworkService($this->createStub(Connection::class), $repository);
        $this->expectException(AccountingFrameworkException::class);
        $service->forDateForSuppliers([11], '2098-02-30');
    }

}
