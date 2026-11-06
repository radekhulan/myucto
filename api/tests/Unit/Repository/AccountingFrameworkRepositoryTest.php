<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class AccountingFrameworkRepositoryTest extends TestCase
{
    public function testBatchLoadsFiftyFourSuppliersWithOneQuery(): void
    {
        $ids = range(1, 54);
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects(self::once())->method('execute')->with([...$ids, '2098-01-01'])->willReturn(true);
        $stmt->expects(self::once())->method('fetchAll')->with(PDO::FETCH_ASSOC)->willReturn([
            ['supplier_id' => '1', 'accounting_framework' => 'cz_504'],
            ['supplier_id' => '54', 'accounting_framework' => 'cz_500'],
        ]);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($stmt);
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('pdo')->willReturn($pdo);
        self::assertSame([1 => 'cz_504', 54 => 'cz_500'], (new AccountingFrameworkRepository($db))->forDateForSuppliers($ids, '2098-01-01'));
    }

    public function testEmptyShortlistDoesNotQueryDatabase(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');
        self::assertSame([], (new AccountingFrameworkRepository($db))->forDateForSuppliers([], '2098-01-01'));
    }
}
