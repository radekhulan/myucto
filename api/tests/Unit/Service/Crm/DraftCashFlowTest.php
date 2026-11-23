<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Crm;

use MyInvoice\Service\Accounting\Obligations\OtherItemForecastService;
use MyInvoice\Service\Crm\DraftCashFlow;
use PDO;
use PHPUnit\Framework\TestCase;

final class DraftCashFlowTest extends TestCase
{
    public function testOutlookIncludesObligationsMergedAfterDraftsWithoutChangingFirmCashflow(): void
    {
        $forecast = [
            'currency' => 'CZK',
            'weeks' => [
                ['week_start' => '2026-01-01', 'week_end' => '2026-01-04', 'in' => 100.0, 'out' => 20.0, 'net' => 80.0, 'running' => 80.0, 'draft_in' => 200.0],
                ['week_start' => '2026-01-05', 'week_end' => '2026-01-11', 'in' => 10.0, 'out' => 50.0, 'net' => -40.0, 'running' => 40.0, 'draft_in' => 70.0],
            ],
            'total_in' => 110.0, 'total_out' => 70.0, 'total_net' => 40.0, 'total_draft_in' => 270.0,
        ];
        $merged = OtherItemForecastService::mergeSourceWeekly(DraftCashFlow::withOutlook($forecast), [
            ['currency' => 'CZK', 'side' => 'payable', 'due_on' => '2026-01-01', 'remaining' => 30.0],
        ]);
        $result = DraftCashFlow::withOutlook($merged);
        self::assertSame(110.0, $result['total_in']);
        self::assertSame(100.0, $result['total_out']);
        self::assertSame(10.0, $result['total_net']);
        self::assertSame(250.0, $result['weeks'][0]['outlook_net']);
        self::assertSame(30.0, $result['weeks'][1]['outlook_net']);
        self::assertSame(280.0, $result['weeks'][1]['outlook_running']);
        self::assertSame(280.0, $result['total_outlook_net']);
        unset($forecast['total_draft_in']);
        self::assertSame($forecast, DraftCashFlow::withOutlook($forecast));
    }

    public function testNullAndPastDatesAreUnscheduledButTodayIsScheduled(): void
    {
        // Produkční sloupec je NOT NULL, obranný predikát musí fungovat i pro NULL.
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE invoices (id INTEGER, status TEXT, invoice_type TEXT, amount_to_pay NUMERIC, paid_total NUMERIC, due_date TEXT)');
        $insert = $pdo->prepare("INSERT INTO invoices VALUES (?, 'draft', 'invoice', 100, 0, ?)");
        foreach ([null, '2026-01-01', '2026-01-02', '2026-01-03'] as $id => $due) $insert->execute([$id + 1, $due]);
        $stmt = $pdo->prepare('SELECT i.id FROM invoices i WHERE ' . DraftCashFlow::eligibleSql() . ' AND (i.due_date IS NULL OR i.due_date < ?) ORDER BY i.id');
        $stmt->execute(['2026-01-02']);
        self::assertSame([1, 2], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
