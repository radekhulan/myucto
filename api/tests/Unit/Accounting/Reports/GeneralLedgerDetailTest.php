<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Reports;

use MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Pdf\GeneralLedgerDetailPdfRenderer;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

final class GeneralLedgerDetailTest extends TestCase
{
    private function data(): array
    {
        return ['accounts' => array_map(static fn ($code) => ['account_code' => $code, 'turnover_md' => 1.11],
            ['321', '501', '501.100', '518.200', '599.999', '602']), 'totals' => ['turnover_md' => 6.66], 'analytics' => true];
    }

    public function testAccountClassIncludesEveryAnalyticAndRecalculatesTotals(): void
    {
        $data = GeneralLedgerDetailService::selectAccounts($this->data(), '5', '5');
        self::assertSame(['501', '501.100', '518.200', '599.999'], array_column($data['accounts'], 'account_code'));
        self::assertEquals(4.44, $data['totals']['turnover_md']);
    }

    public function testInclusiveRangeAndSingleAnalytic(): void
    {
        self::assertSame(['501', '501.100', '518.200'], array_column(GeneralLedgerDetailService::selectAccounts($this->data(), '501', '518')['accounts'], 'account_code'));
        self::assertSame(['501.100'], array_column(GeneralLedgerDetailService::selectAccounts($this->data(), '501.100', '501.100')['accounts'], 'account_code'));
        self::assertSame([], GeneralLedgerDetailService::selectAccounts($this->data(), '7', '7')['accounts']);
        self::assertCount(6, GeneralLedgerDetailService::selectAccounts($this->data())['accounts']);
    }

    public function testInvalidRangeIsValidationError(): void
    {
        $this->expectException(ReportException::class);
        GeneralLedgerDetailService::selectAccounts($this->data(), '6', '5');
    }

    public function testInvalidCodeIsValidationError(): void
    {
        $this->expectException(ReportException::class);
        GeneralLedgerDetailService::selectAccounts($this->data(), '5%');
    }

    public function testRangeAcceptsEveryAccountCodeTheChartAllows(): void
    {
        $data = ['accounts' => array_map(static fn ($code) => ['account_code' => $code, 'turnover_md' => 1],
            ['211', '211200', '221.KB', '221.KC', '501']), 'totals' => ['turnover_md' => 5], 'analytics' => true];
        self::assertSame(['211200'], array_column(GeneralLedgerDetailService::selectAccounts($data, '211200', '211200')['accounts'], 'account_code'));
        self::assertSame(['221.KB'], array_column(GeneralLedgerDetailService::selectAccounts($data, '221.KB', '221.KB')['accounts'], 'account_code'));
        self::assertSame(['221.KB'], array_column(GeneralLedgerDetailService::selectAccounts($data, '221.kb', '221.kb')['accounts'], 'account_code'));
        $this->expectException(ReportException::class);
        GeneralLedgerDetailService::selectAccounts($data, '12345678901');
    }

    public function testAnalyticBoundWithoutBreakdownSelectsWholeSyntheticOnBothEnds(): void
    {
        $data = ['accounts' => array_map(static fn ($code) => ['account_code' => $code, 'turnover_md' => 1],
            ['321', '501', '502', '518', '602']), 'totals' => ['turnover_md' => 5], 'analytics' => false];
        self::assertSame(['501'], array_column(GeneralLedgerDetailService::selectAccounts($data, '501.100', '501.100')['accounts'], 'account_code'));
        self::assertSame(['501', '502', '518'], array_column(GeneralLedgerDetailService::selectAccounts($data, '501.100', '518.200')['accounts'], 'account_code'));
        self::assertSame(['502', '518'], array_column(GeneralLedgerDetailService::selectAccounts($data, '502', '518')['accounts'], 'account_code'));
    }

    public function testAnalyticLowerBoundUnderItsSyntheticUpperBoundIsValid(): void
    {
        // 501.100 až 501: horní mez je prefix dolní, rozsah je platný (není to „od > do“).
        self::assertSame(['501.100'], array_column(GeneralLedgerDetailService::selectAccounts($this->data(), '501.100', '501')['accounts'], 'account_code'));
        $synthetic = ['accounts' => array_map(static fn ($code) => ['account_code' => $code, 'turnover_md' => 1],
            ['321', '501', '502']), 'totals' => ['turnover_md' => 3], 'analytics' => false];
        self::assertSame(['501'], array_column(GeneralLedgerDetailService::selectAccounts($synthetic, '501.100', '501')['accounts'], 'account_code'));
    }

    public function testLowercaseChartCodeMatchesUppercaseBound(): void
    {
        // Osnova povoluje malá písmena v kódu; mez se porovnává bez ohledu na velikost písmen.
        $data = ['accounts' => array_map(static fn ($code) => ['account_code' => $code, 'turnover_md' => 1],
            ['221.ka', '221.kb', '221.kc']), 'totals' => ['turnover_md' => 3], 'analytics' => true];
        self::assertSame(['221.kb'], array_column(GeneralLedgerDetailService::selectAccounts($data, '221.KB', '221.KB')['accounts'], 'account_code'));
    }

    public function testExportSizeLimitStopsBeforeRendering(): void
    {
        $limit = GeneralLedgerDetailService::MAX_LINES;
        $page = GeneralLedgerDetailService::PDF_ACCOUNT_LINES;
        $data = ['accounts' => [['line_count' => $limit - 2 * $page - 1], ['line_count' => 1]]];
        GeneralLedgerDetailService::assertExportSize($data);
        $data['accounts'][0]['line_count']++;
        try {
            GeneralLedgerDetailService::assertExportSize($data);
            self::fail('Rozpis nad limitem PDF musí skončit validační chybou.');
        } catch (ReportException $e) {
            self::assertSame('export_too_large', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }

    public function testExportSizeRefusesSummaryWithoutLineCounts(): void
    {
        // Souhrnná kniha (bez `forDetail`) nemá počty řádků: limit nesmí projít naslepo.
        $this->expectException(\LogicException::class);
        GeneralLedgerDetailService::assertExportSize(['accounts' => [['account_code' => '501', 'turnover_md' => 1]]]);
    }

    public function testPdfLimitCountsPageOfEveryAccountWithoutLines(): void
    {
        $accounts = array_fill(0, intdiv(GeneralLedgerDetailService::MAX_LINES, GeneralLedgerDetailService::PDF_ACCOUNT_LINES), ['line_count' => 0]);
        GeneralLedgerDetailService::assertExportSize(['accounts' => $accounts]);
        $accounts[] = ['line_count' => 0];
        $this->expectException(ReportException::class);
        GeneralLedgerDetailService::assertExportSize(['accounts' => $accounts]);
    }

    public function testPdfFlowsAccountsWithContinuationHeaderSaldoAndIc(): void
    {
        $account = static fn (string $code, float $md, float $d): array => ['account_code' => $code, 'name' => 'Syntetický účet ' . $code,
            'opening_md' => 0, 'opening_d' => 0, 'turnover_md' => $md, 'turnover_d' => $d,
            'closing_md' => max(0, $md - $d), 'closing_d' => max(0, $d - $md)];
        $events = (static function () use ($account) {
            foreach ([['501', 3, 1], ['502', 2, 0]] as [$code, $md, $d]) {
                $a = $account($code, $md, $d);
                yield ['kind' => 'start', 'account' => $a];
                yield ['kind' => 'line', 'item' => ['account_code' => $code, 'entry_date' => '2099-01-02', 'document_no' => 'DEMO-' . $code,
                    'description' => 'Krátký', 'counter_accounts' => '321', 'side' => 'debit', 'amount' => $md, 'balance' => $md]];
                yield ['kind' => 'end', 'account' => $a];
            }
            // Dlouhý účet přes víc stran: hlavička pokračování nese jeho kód.
            $a = $account('518', 200, 0);
            yield ['kind' => 'start', 'account' => $a];
            for ($i = 1; $i <= 200; $i++) {
                yield ['kind' => 'line', 'item' => ['account_code' => '518', 'entry_date' => '2099-01-03', 'document_no' => sprintf('LONG-%03d', $i),
                    'description' => 'Řádek', 'counter_accounts' => '321', 'side' => 'debit', 'amount' => 1, 'balance' => $i]];
            }
            yield ['kind' => 'end', 'account' => $a];
        })();
        $bytes = (new GeneralLedgerDetailPdfRenderer())->render(['from' => '2099-01-01', 'to' => '2099-12-31', 'supplier_name' => 'Syntetická firma',
            'supplier_ic' => '12345678', 'account_from' => '5', 'account_to' => '5', 'draft_count' => 0, 'events' => $events]);
        $pages = (new Parser())->parseContent($bytes)->getPages();
        $first = $pages[0]->getText();
        // Krátké účty jsou na první straně za sebou, ne každý na své.
        self::assertStringContainsString('DEMO-501', $first);
        self::assertStringContainsString('DEMO-502', $first);
        self::assertStringContainsString('IČ: 12345678', $first);
        self::assertStringNotContainsString('pokračování', $first);
        self::assertStringContainsString('Saldo obratů', $first);
        self::assertGreaterThan(1, count($pages));
        self::assertStringContainsString('518 - Syntetický účet 518 (pokračování)', $pages[1]->getText());
        self::assertStringContainsString('2,00', $first, 'Saldo 501 = 3 − 1.');
    }

    public function testExportHeadersShowAccountClassZeroRange(): void
    {
        // Třída 0 (dlouhodobý majetek): kód „0“ je platná mez, hlavička ji nesmí vynechat jako prázdnou.
        $account = ['account_id' => 1, 'account_code' => '013', 'name' => 'Software', 'account_type' => 'A', 'is_synthetic' => true,
            'parent_id' => null, 'parent_code' => null, 'parent_name' => null, 'months' => [],
            'opening_md' => 10, 'opening_d' => 0, 'turnover_md' => 0, 'turnover_d' => 0, 'closing_md' => 10, 'closing_d' => 0];
        $summary = ['period' => ['id' => 1, 'fiscal_year' => 2099, 'starts_on' => '2099-01-01', 'ends_on' => '2099-12-31'],
            'all_periods' => false, 'from' => '2099-01-01', 'to' => '2099-12-31', 'analytics' => false, 'draft_count' => 0,
            'months' => [], 'accounts' => [$account], 'account_from' => '0', 'account_to' => '0',
            'totals' => ['opening_md' => 10, 'opening_d' => 0, 'turnover_md' => 0, 'turnover_d' => 0, 'closing_md' => 10, 'closing_d' => 0]];

        $pdf = (new Parser())->parseContent((new \MyInvoice\Service\Pdf\GeneralLedgerPdfRenderer())->render($summary))->getText();
        self::assertStringContainsString('Účty: 0 - 0', $pdf);

        $file = tempnam(sys_get_temp_dir(), 'gl');
        try {
            file_put_contents($file, (new \MyInvoice\Service\Accounting\Reports\ReportXlsxExporter())->generalLedger($summary)['bytes']);
            $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getActiveSheet();
            self::assertStringContainsString('Účty: 0 - 0', (string) $sheet->getCell('A2')->getValue());
        } finally {
            @unlink($file);
        }

        $events = (static function () use ($account) {
            yield ['kind' => 'start', 'account' => $account];
            yield ['kind' => 'end', 'account' => $account];
        })();
        $detail = (new Parser())->parseContent((new GeneralLedgerDetailPdfRenderer())->render(['from' => '2099-01-01', 'to' => '2099-12-31',
            'supplier_name' => 'Syntetická firma', 'supplier_ic' => '12345678', 'account_from' => '0', 'account_to' => '0',
            'draft_count' => 3, 'events' => $events]))->getText();
        self::assertStringContainsString('Účty: 0 - 0', $detail);
        // Počet konceptů je za celé období, ne za vybrané účty; hlavička to musí říct.
        self::assertStringContainsString('v období 3 za všechny účty', $detail);
    }

    public function testPdfKeepsAllRowsAcrossChunksAndAccountEnd(): void
    {
        $a = ['account_code' => '501.100', 'name' => 'Testovací materiál', 'opening_md' => 0, 'opening_d' => 0,
            'turnover_md' => 145, 'turnover_d' => 0, 'closing_md' => 145, 'closing_d' => 0];
        $events = (static function () use ($a) {
            yield ['kind' => 'start', 'account' => $a];
            for ($i = 1; $i <= 145; $i++) {
                yield ['kind' => 'line', 'item' => ['account_code' => '501.100', 'entry_date' => '2099-01-02',
                    'document_no' => sprintf('DEMO-%04d', $i), 'description' => 'Syntetický test <značka>',
                    'counter_accounts' => '321', 'side' => 'debit', 'amount' => 1, 'balance' => $i]];
            }
            yield ['kind' => 'end', 'account' => $a];
        })();
        $bytes = (new GeneralLedgerDetailPdfRenderer())->render(['from' => '2099-01-01', 'to' => '2099-12-31',
            'account_from' => '5', 'account_to' => '5', 'draft_count' => 0, 'events' => $events]);
        $pdf = (new Parser())->parseContent($bytes);
        self::assertGreaterThan(1, count($pdf->getPages()));
        $text = $pdf->getText();
        self::assertSame(145, substr_count($text, 'DEMO-'));
        self::assertStringContainsString('DEMO-0145', $text);
        self::assertStringContainsString('Konečný zůstatek', $text);
        self::assertStringContainsString('<značka>', $text);
    }
}
