<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Action\Admin\Import\AiExtractPdfAction;
use MyInvoice\Service\Import\AiPdfExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Jednorázové načtení dokladu bez položek: řádky téže sazby DPH se sečtou do jednoho,
 * odpočet zdaněné zálohy zůstane samostatně a součet dokladu se nezmění.
 */
final class AiPdfExtractorSummaryLinesTest extends TestCase
{
    private const RATE_21 = 21;
    private const RATE_12 = 12;

    /** @return array<string,mixed> */
    private static function line(string $description, float $qty, float $price, int $rateId, array $extra = []): array
    {
        return $extra + [
            'description'            => $description,
            'quantity'               => $qty,
            'unit'                   => 'ks',
            'unit_price_without_vat' => $price,
            'vat_rate_id'            => $rateId,
            'order_index'            => 0,
            'accrual_from'           => null,
            'accrual_to'             => null,
            'seller_item_code'       => null,
            'item_ean'               => null,
            'intrastat_cn8_code'     => null,
            'intrastat_country_of_origin' => null,
        ];
    }

    /** @param list<array<string,mixed>> $lines */
    private static function total(array $lines): float
    {
        $sum = 0.0;
        foreach ($lines as $l) {
            $sum += round((float) $l['quantity'] * (float) $l['unit_price_without_vat'], 2);
        }
        return round($sum, 2);
    }

    public function testMergesLinesPerRateKeepsAdvanceAndTotal(): void
    {
        $items = [
            self::line('Syntetická služba A', 2, 1000.0, self::RATE_21, ['seller_item_code' => 'SA-1']),
            self::line('Syntetická služba B', 1, 500.0, self::RATE_21),
            self::line('Sleva 10 %', 1, -100.0, self::RATE_21),
            self::line('Syntetická kniha', 1, 200.0, self::RATE_12),
            // Haléřové zaokrouhlení: 7 × 0,125 = 0,875 → řádek 0,88 (doklad sčítá zaokrouhlené řádky).
            self::line('Záložka do knihy', 7, 0.125, self::RATE_12),
            self::line('Záložka dárková', 7, 0.125, self::RATE_12),
            self::line('Odpočet zálohy č. ZF-SYN-1', 1, -1000.0, self::RATE_21),
        ];

        $lines = AiPdfExtractor::summaryLinesByRate($items, false);

        self::assertCount(3, $lines);
        self::assertSame(self::total($items), self::total($lines), 'součet dokladu se sloučením nemění');
        self::assertSame(1601.76, self::total($lines));

        [$r21, $r12, $advance] = $lines;
        self::assertSame(self::RATE_21, $r21['vat_rate_id']);
        self::assertSame(1.0, $r21['quantity']);
        self::assertSame(2400.0, $r21['unit_price_without_vat'], 'sleva se započte do řádku své sazby');
        self::assertSame('Syntetická služba A, Syntetická služba B, Sleva 10 %', $r21['description']);
        self::assertNull($r21['seller_item_code'], 'kód zboží jednoho řádku nepatří součtovému řádku');

        self::assertSame(self::RATE_12, $r12['vat_rate_id']);
        self::assertSame(201.76, $r12['unit_price_without_vat']);

        self::assertSame('Odpočet zálohy č. ZF-SYN-1', $advance['description'], 'odpočet zdaněné zálohy zůstává samostatně');
        self::assertSame(-1000.0, $advance['unit_price_without_vat']);
        self::assertSame(self::RATE_21, $advance['vat_rate_id']);

        self::assertSame([0, 1, 2], array_column($lines, 'order_index'));
    }

    public function testSingleLinePerRateIsKeptVerbatim(): void
    {
        $items = [
            self::line('Jediná položka', 3, 33.5, self::RATE_21, ['seller_item_code' => 'X-1', 'order_index' => 5]),
            self::line('Jiná sazba', 1, 10.0, self::RATE_12),
        ];

        $lines = AiPdfExtractor::summaryLinesByRate($items, false);

        self::assertCount(2, $lines);
        self::assertSame(3, (int) $lines[0]['quantity']);
        self::assertSame('X-1', $lines[0]['seller_item_code']);
        self::assertSame(0, $lines[0]['order_index']);
    }

    public function testCreditNoteKeepsNegativeQuantity(): void
    {
        $items = [
            self::line('Vrácené zboží', -2, 150.0, self::RATE_21),
            self::line('Vrácená doprava', -1, 99.99, self::RATE_21),
        ];

        $lines = AiPdfExtractor::summaryLinesByRate($items, true);

        self::assertCount(1, $lines);
        self::assertSame(-1.0, $lines[0]['quantity']);
        self::assertSame(399.99, $lines[0]['unit_price_without_vat']);
        self::assertSame(self::total($items), self::total($lines));
    }

    public function testSharedAccrualPeriodSurvivesDifferentIsDropped(): void
    {
        $period = ['accrual_from' => '2026-01-01', 'accrual_to' => '2026-12-31'];
        $same = AiPdfExtractor::summaryLinesByRate([
            self::line('Licence A', 1, 100.0, self::RATE_21, $period),
            self::line('Licence B', 1, 200.0, self::RATE_21, $period),
        ], false);
        self::assertSame('2026-01-01', $same[0]['accrual_from']);
        self::assertSame('2026-12-31', $same[0]['accrual_to']);

        $mixed = AiPdfExtractor::summaryLinesByRate([
            self::line('Licence A', 1, 100.0, self::RATE_21, $period),
            self::line('Doprava', 1, 200.0, self::RATE_21),
        ], false);
        self::assertNull($mixed[0]['accrual_from']);
        self::assertNull($mixed[0]['accrual_to']);
    }

    public function testZeroSumGroupIsDroppedWhenSomethingRemains(): void
    {
        $lines = AiPdfExtractor::summaryLinesByRate([
            self::line('Dárek', 1, 50.0, self::RATE_12),
            self::line('Sleva na dárek', 1, -50.0, self::RATE_12),
            self::line('Služba', 1, 300.0, self::RATE_21),
        ], false);

        self::assertCount(1, $lines);
        self::assertSame(self::RATE_21, $lines[0]['vat_rate_id']);
    }

    #[DataProvider('withoutItemsParams')]
    public function testWithoutItemsRequestParameter(array $params, bool $expected): void
    {
        self::assertSame($expected, AiExtractPdfAction::withoutItemsRequested($params));
    }

    /** @return iterable<string, array{array<string,mixed>, bool}> */
    public static function withoutItemsParams(): iterable
    {
        yield 'chybí' => [[], false];
        yield 'query 1' => [['without_items' => '1'], true];
        yield 'json true' => [['without_items' => true], true];
        yield 'query 0' => [['without_items' => '0'], false];
        yield 'json false' => [['without_items' => false], false];
        yield 'nesmysl' => [['without_items' => 'snad'], false];
    }
}
