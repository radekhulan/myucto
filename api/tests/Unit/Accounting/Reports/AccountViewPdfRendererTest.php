<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\StatementAccountColumns;
use MyInvoice\Service\Pdf\AccountViewPdfRenderer;
use PHPUnit\Framework\TestCase;

/**
 * PDF po účtech kreslí stejné řádky `columns.*.lines` jako obrazovka a XLSX, na šířku.
 */
final class AccountViewPdfRendererTest extends TestCase
{
    public function testRendersColumnsLandscapeForBothParts(): void
    {
        $data = $this->data();
        foreach (['balance', 'profit_loss'] as $part) {
            foreach (['czk', 'thousands'] as $unit) {
                $pdf = (new AccountViewPdfRenderer())->render($data + ['part' => $part, 'unit' => $unit]);
                self::assertStringStartsWith('%PDF', $pdf);
                self::assertMatchesRegularExpression('#/MediaBox \[0 0 841\.89\d* 595\.28\d*\]#', $pdf, 'PDF po účtech je A4 na šířku.');
            }
        }
    }

    public function testRequiresColumnView(): void
    {
        $data = $this->data();
        unset($data['columns']);
        $this->expectException(\LogicException::class);
        (new AccountViewPdfRenderer())->render($data + ['part' => 'balance', 'unit' => 'czk']);
    }

    /** @return array<string,mixed> */
    private function data(): array
    {
        $row = static fn (int $id, string $code, string $name, string $type, string $side, float $psMd, float $psD, float $toMd, float $toD): array => [
            'id' => $id, 'account_code' => $code, 'name' => $name, 'account_type' => $type, 'normal_side' => $side,
            'parent_id' => null, 'parent_code' => null, 'parent_name' => null, 'parent_normal_side' => null,
            'ps_md' => $psMd, 'ps_d' => $psD, 'to_md' => $toMd, 'to_d' => $toD,
        ];
        $raw = [
            $row(1, '211', 'Pokladna', 'asset', 'debit', 1000.0, 0.0, 500.0, 0.0),
            $row(2, '411', 'Základní kapitál', 'equity', 'credit', 0.0, 1000.0, 0.0, 0.0),
            $row(3, '518', 'Ostatní služby', 'expense', 'debit', 0.0, 0.0, 200.0, 0.0),
            $row(4, '602', 'Tržby z prodeje služeb', 'revenue', 'credit', 0.0, 0.0, 0.0, 700.0),
        ];
        $columns = StatementAccountColumns::build($raw, [['account_id' => 1, 'month' => '2099-06', 'md' => 500.0, 'd' => 0.0]],
            static fn (string $code): string => 'operating', ['operating', 'financial', 'unassigned', 'tax', 'transfer'],
            ['hide_zero' => true, 'analytics' => true, 'from' => '2099-01-01', 'month_from' => '2099-06-01', 'as_of' => '2099-06-30']);

        return [
            'as_of'   => '2099-06-30',
            'period'  => ['fiscal_year' => 2099],
            'entity'  => ['name' => 'Testovací firma', 'ico' => null],
            'checks'  => ['profit_matches' => true, 'profit_balance' => 500.0, 'profit_loss' => 500.0],
            'columns' => $columns,
        ];
    }
}
