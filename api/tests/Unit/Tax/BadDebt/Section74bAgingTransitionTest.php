<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\BadDebt;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Section74bCorrectionRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Tax\BadDebt\Section74bService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class Section74bAgingTransitionTest extends TestCase
{
    public function testSameDueDateUsesDifferentAgingAtTaxPointBoundary(): void
    {
        $constants = $this->createStub(PDOStatement::class);
        $constants->method('fetchColumn')->willReturn(false);

        $invoice = [
            'issue_date' => '2027-01-02',
            'due_date' => '2027-01-15',
            'total_with_vat' => 12100,
            'total_vat' => 2100,
            'advance_paid_amount' => 0,
            'bank_paid' => 0,
            'cash_paid' => 0,
            'vat_deduction_percent' => 100,
            'status' => 'received',
            'vendor_dic' => null,
        ];
        $invoices = $this->createStub(PDOStatement::class);
        $invoices->method('fetchAll')->willReturn([
            ['id' => 1, 'tax_date' => '2026-12-31'] + $invoice,
            ['id' => 2, 'tax_date' => '2027-01-01'] + $invoice,
            ['id' => 3, 'tax_date' => null, 'issue_date' => '2026-12-31'] + $invoice,
        ]);

        $ledger = $this->createStub(PDOStatement::class);
        $ledger->method('fetchAll')->willReturn([]);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            static function (string $sql) use ($constants, $invoices, $ledger): PDOStatement {
                return match (true) {
                    str_contains($sql, 'FROM tax_constants') => $constants,
                    str_contains($sql, 'FROM purchase_invoices pi') => $invoices,
                    str_contains($sql, 'FROM vat_s74b_corrections') => $ledger,
                };
            },
        );
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $db->method('hasColumn')->willReturn(false);
        $service = new Section74bService(
            $db,
            new Section74bCorrectionRepository($db),
            new ActivityLogger($db),
            new TaxConstantsRepository($db),
        );

        self::assertSame([], $service->previewAging(1, 2027, 3)['rows']);
        $april = $service->previewAging(1, 2027, 4);
        self::assertSame([2], array_column($april['rows'], 'purchase_invoice_id'));
        self::assertSame(2100.0, $april['totals']['reduction']);
        self::assertSame([2], array_column($service->previewAging(1, 2027, 6)['rows'], 'purchase_invoice_id'));
        self::assertSame([1, 2, 3], array_column($service->previewAging(1, 2027, 7)['rows'], 'purchase_invoice_id'));
    }
}
