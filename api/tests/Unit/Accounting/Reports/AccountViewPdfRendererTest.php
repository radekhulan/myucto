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

    /**
     * Odhad do konce roku jde do PDF pod tabulku, označený jako odhad mimo účetnictví,
     * se stejným pořadím řádků jako obrazovka; rozvaha navíc se změnami rozvahy a 341.
     */
    public function testEstimateBlockFollowsScreenOrder(): void
    {
        $renderer = new AccountViewPdfRenderer();
        $html = $renderer->html($this->data() + ['part' => 'profit_loss', 'unit' => 'czk', 'estimate' => $this->estimate()]);
        $text = self::text($html);

        self::assertStringContainsString('Odhad do konce roku (nezaúčtováno)', $text);
        self::assertStringContainsString('ODHAD, NEZAÚČTOVÁNO', $text);
        self::assertStringContainsString('Odpisy na 551 mimo karty majetku.', $text, 'Upozornění k odpisům jsou v PDF.');
        $order = [
            'Odhad výsledku hospodaření před zdaněním 54 238,97',
            'Odhad výsledku hospodaření po zdanění 43 931,97',
            'Zaúčtovaný výsledek k 27.11.2099 -2 291 099,41',
            'Rozpuštění výnosů příštích období (384), zaúčtováno po 27.11.2099 + 3 118 243,66',
            'Rozpuštění nákladů příštích období (381), zaúčtováno po 27.11.2099 − 38 944,85',
            'Odpisy (551), zaúčtováno po 27.11.2099 − 624 099,79',
            'Zaúčtovaný výsledek do 31.12.2099 164 099,61',
            'Časové rozlišení nákladů příštích období (381) + 23 368,36',
            'Odpisy roku podle odpisového plánu (nezaúčtované) − 133 229,00',
            'Odhad výsledku hospodaření před zdaněním 54 238,97',
            'Připočitatelné položky (ř. 70) + 0,00',
            'Základ daně (ř. 200) 54 000,00',
            'Odhad daně z příjmů (po odečtech a slevách) − 10 307,00',
            'Zaplacené zálohy na daň (§ 38a) 0,00',
            'Odhad doplatku daně 10 307,00',
            'Odhad výsledku hospodaření po zdanění 43 931,97',
        ];
        $pos = 0;
        foreach ($order as $needle) {
            $found = mb_strpos($text, $needle, $pos);
            self::assertNotFalse($found, 'Chybí nebo je mimo pořadí: ' . $needle);
            $pos = $found + 1;
        }
        self::assertStringNotContainsString('Odhadované změny rozvahy', $text, 'Změny rozvahy patří jen k rozvaze.');

        $balance = self::text(($renderer->html($this->data() + ['part' => 'balance', 'unit' => 'czk', 'estimate' => $this->estimate()])));
        self::assertStringContainsString('Odhadované změny rozvahy z návrhů uzávěrky a daně z příjmů', $balance);
        self::assertStringContainsString('Daň z příjmů (591 / 341) 341 (pasiva)', $balance);
        self::assertStringContainsString('Odhad daně k doplacení na účtu 341 (daň minus zálohy) 10 307,00', $balance);
        self::assertStringContainsString('07x/08x (aktiva) − 133 229,00', $balance);

        $plain = $renderer->html($this->data() + ['part' => 'profit_loss', 'unit' => 'czk']);
        self::assertStringNotContainsString('NEZAÚČTOVÁNO', $plain, 'Bez odhadu (uzavřené období) blok v PDF není.');
        self::assertStringStartsWith('%PDF', $renderer->render($this->data() + ['part' => 'balance', 'unit' => 'thousands', 'estimate' => $this->estimate()]));
    }

    public function testRequiresColumnView(): void
    {
        $data = $this->data();
        unset($data['columns']);
        $this->expectException(\LogicException::class);
        (new AccountViewPdfRenderer())->render($data + ['part' => 'balance', 'unit' => 'czk']);
    }

    private static function text(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode((string) preg_replace('/<[^>]+>/', ' ', $html))));
    }

    /** @return array<string,mixed> */
    private function estimate(): array
    {
        return [
            'applicable' => true,
            'period' => ['id' => 1, 'fiscal_year' => 2099, 'starts_on' => '2099-01-01', 'ends_on' => '2099-12-31', 'status' => 'open'],
            'vh_posted' => 164099.61,
            'bridge' => ['as_of' => '2099-11-27', 'ends_on' => '2099-12-31', 'vh_as_of' => -2291099.41, 'vh_posted' => 164099.61, 'groups' => [
                ['key' => 'deferred_revenue', 'amount' => 3118243.66],
                ['key' => 'prepaid_expenses', 'amount' => -38944.85],
                ['key' => 'depreciation', 'amount' => -624099.79],
            ]],
            'closing_items' => [
                ['key' => 'prepaid_expense_accrual', 'label_key' => 'taxReturn.proj_prepaid', 'amount' => 23368.36, 'sign' => 1, 'optional' => false],
                ['key' => 'depreciation', 'label_key' => 'taxReturn.proj_depreciation', 'amount' => 133229.0, 'sign' => -1, 'optional' => false],
            ],
            'projection_warnings' => [],
            'depreciation_warnings' => ['Odpisy na 551 mimo karty majetku.'],
            'is_projection' => true,
            'vh_before_tax' => 54238.97,
            'increases' => 0.0, 'decreases' => 238.97, 'tax_base' => 54000.0,
            'tax' => 10307.0, 'advances_paid' => 0.0, 'advances_source' => 'none', 'balance_due' => 10307.0,
            'vh_after_tax' => 43931.97,
            'balance_effects' => [
                ['key' => 'prepaid_expense_accrual', 'label_key' => 'taxReturn.proj_prepaid', 'accounts' => '381', 'side' => 'asset', 'assets' => 23368.36, 'liabilities' => 0.0, 'optional' => false],
                ['key' => 'depreciation', 'label_key' => 'taxReturn.proj_depreciation', 'accounts' => '07x/08x', 'side' => 'asset', 'assets' => -133229.0, 'liabilities' => 0.0, 'optional' => false],
                ['key' => 'income_tax', 'label_key' => '', 'accounts' => '341', 'side' => 'liability', 'assets' => 0.0, 'liabilities' => 10307.0, 'optional' => false],
            ],
            'balance_check' => ['assets' => -109860.64, 'liabilities' => 10307.0, 'difference' => -120167.64, 'expected' => -120167.64, 'matches' => true],
        ];
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
