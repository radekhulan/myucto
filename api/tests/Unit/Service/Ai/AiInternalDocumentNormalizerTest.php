<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ai;

use MyInvoice\Service\Ai\AiInternalDocumentNormalizer;
use PHPUnit\Framework\TestCase;

final class AiInternalDocumentNormalizerTest extends TestCase
{
    private const CHART = ['501' => 'Spotřeba materiálu', '518' => 'Ostatní služby', '389' => 'Dohadné účty pasivní'];

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    private static function doc(array $changes = []): array
    {
        return $changes + [
            'kind' => 'reclassification', 'title' => 'Přeúčtování 518 na 501', 'entry_date' => '2026-09-30',
            'document_date' => '', 'partner_name' => '', 'partner_ico' => '', 'note' => '',
            'lines' => [
                ['account_code' => '501', 'side' => 'debit', 'amount' => 5000, 'dimension_value_ids' => []],
                ['account_code' => '518', 'side' => 'credit', 'amount' => 5000, 'dimension_value_ids' => []],
            ],
        ];
    }

    public function testBalancedReclassificationHasNoProblems(): void
    {
        $out = AiInternalDocumentNormalizer::normalize([self::doc()], self::CHART, '2026-10-29');

        self::assertCount(1, $out);
        self::assertSame('reclassification', $out[0]['kind']);
        self::assertSame('2026-09-30', $out[0]['entry_date']);
        self::assertSame('2026-09-30', $out[0]['document_date'], 'datum dokladu bez hodnoty = datum případu');
        self::assertTrue($out[0]['balanced']);
        self::assertSame(5000.0, $out[0]['total_debit']);
        self::assertSame(5000.0, $out[0]['total_credit']);
        self::assertSame('Spotřeba materiálu', $out[0]['lines'][0]['account_name']);
        self::assertSame([], $out[0]['problems']);
    }

    public function testUnbalancedEntryIsReportedInHalers(): void
    {
        $doc = self::doc(['lines' => [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 5000.004, 'dimension_value_ids' => []],
            ['account_code' => '518', 'side' => 'credit', 'amount' => 4999.99, 'dimension_value_ids' => []],
        ]]);
        $out = AiInternalDocumentNormalizer::normalize([$doc], self::CHART, '2026-10-29');

        self::assertFalse($out[0]['balanced']);
        self::assertSame(5000.0, $out[0]['lines'][0]['amount'], 'částka se zaokrouhlí na haléře');
        self::assertSame(['Strany MD a D se neshodují (MD 5 000,00, D 4 999,99).'], $out[0]['problems']);
    }

    public function testOneSidedEntryIsAProblem(): void
    {
        $doc = self::doc(['lines' => [['account_code' => '501', 'side' => 'debit', 'amount' => 100, 'dimension_value_ids' => []]]]);
        $out = AiInternalDocumentNormalizer::normalize([$doc], self::CHART, '2026-10-29');

        self::assertFalse($out[0]['balanced']);
        self::assertSame(['Doklad musí mít řádky na straně MD i D.'], $out[0]['problems']);
    }

    public function testUnknownAccountIsFlagged(): void
    {
        $doc = self::doc(['lines' => [
            ['account_code' => '5 01', 'side' => 'debit', 'amount' => 100, 'dimension_value_ids' => []],
            ['account_code' => '999', 'side' => 'credit', 'amount' => 100, 'dimension_value_ids' => []],
        ]]);
        $out = AiInternalDocumentNormalizer::normalize([$doc], self::CHART, '2026-10-29');

        self::assertSame('501', $out[0]['lines'][0]['account_code'], 'mezery v kódu pryč');
        self::assertSame(['999'], $out[0]['unknown_accounts']);
        self::assertNull($out[0]['lines'][1]['account_name']);
        self::assertSame(['Účet 999 není v účtové osnově firmy.'], $out[0]['problems']);
    }

    public function testInvalidValuesAreCorrectedOrDropped(): void
    {
        $out = AiInternalDocumentNormalizer::normalize([
            self::doc(['kind' => 'payment', 'entry_date' => '2026-02-31', 'partner_ico' => '000 000 19', 'partner_name' => 'Odběratel s.r.o.',
                'lines' => [
                    ['account_code' => '501', 'side' => 'debit', 'amount' => 100, 'dimension_value_ids' => [3, '3', -1, 'x', 7]],
                    ['account_code' => '518', 'side' => 'MD', 'amount' => 100, 'dimension_value_ids' => []],
                    ['account_code' => '518', 'side' => 'credit', 'amount' => -100, 'dimension_value_ids' => []],
                    ['account_code' => '518', 'side' => 'credit', 'amount' => 100, 'dimension_value_ids' => []],
                ]]),
            self::doc(['title' => '   ']),
            self::doc(['lines' => []]),
            'nesmysl',
        ], self::CHART, '2026-10-29', ['00000019' => ['id' => 42, 'name' => 'Odběratel s.r.o.']]);

        self::assertCount(1, $out, 'doklad bez popisu nebo řádků vypadne');
        self::assertSame('other', $out[0]['kind'], 'neznámý druh = ostatní');
        self::assertSame('2026-10-29', $out[0]['entry_date'], 'neexistující datum = dnešek');
        self::assertSame(42, $out[0]['partner_id']);
        self::assertSame('00000019', $out[0]['partner_ico']);
        self::assertCount(2, $out[0]['lines'], 'řádek s neplatnou stranou nebo nekladnou částkou vypadne');
        self::assertSame([3, 7], $out[0]['lines'][0]['dimension_value_ids']);
        self::assertTrue($out[0]['balanced']);
    }

    public function testNonArrayAndLimits(): void
    {
        self::assertSame([], AiInternalDocumentNormalizer::normalize('x', self::CHART, '2026-10-29'));
        $many = array_fill(0, AiInternalDocumentNormalizer::MAX_DOCUMENTS + 5, self::doc());
        self::assertCount(AiInternalDocumentNormalizer::MAX_DOCUMENTS, AiInternalDocumentNormalizer::normalize($many, self::CHART, '2026-10-29'));
    }
}
