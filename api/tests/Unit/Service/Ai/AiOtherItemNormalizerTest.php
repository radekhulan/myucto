<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ai;

use MyInvoice\Service\Ai\AiOtherItemNormalizer;
use PHPUnit\Framework\TestCase;

final class AiOtherItemNormalizerTest extends TestCase
{
    private const CHART = ['353' => 'Pohledávky za upsaný základní kapitál', '411' => 'Základní kapitál', '461' => 'Závazky k úvěrovým institucím'];

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    private static function item(array $changes = []): array
    {
        return $changes + [
            'side' => 'payable', 'kind' => 'loan', 'title' => 'Úvěr – splátkový kalendář', 'partner_name' => 'Banka Test a.s.',
            'partner_ico' => '00000019', 'amount' => 30000, 'issued_on' => '2026-01-10', 'due_on' => '2026-04-10',
            'variable_symbol' => 'VS 123', 'account_code' => '461', 'counter_account_code' => '221', 'note' => '',
            'installments' => [
                ['due_on' => '2026-02-10', 'amount' => 10000],
                ['due_on' => '2026-03-10', 'amount' => 10000],
                ['due_on' => '2026-04-10', 'amount' => 10000],
            ],
        ];
    }

    public function testValidLoanWithInstallmentsMatchesPartnerAndFlagsUnknownAccounts(): void
    {
        $out = AiOtherItemNormalizer::normalize([self::item()], self::CHART, '2026-10-11', ['00000019' => ['id' => 7, 'name' => 'Banka Test a.s.']]);

        self::assertCount(1, $out);
        self::assertSame('payable', $out[0]['side']);
        self::assertSame(7, $out[0]['partner_id']);
        self::assertSame('123', $out[0]['variable_symbol']);
        self::assertSame('Závazky k úvěrovým institucím', $out[0]['account_name']);
        self::assertNull($out[0]['counter_account_name']);
        self::assertSame(['221'], $out[0]['unknown_accounts']);
        self::assertCount(3, $out[0]['installments']);
        self::assertTrue($out[0]['installments_valid']);
    }

    public function testHiddenBuiltinKindFallsBackToOtherOnItsSideOnly(): void
    {
        $out = AiOtherItemNormalizer::normalize([self::item(), self::item(['side' => 'receivable'])], self::CHART, '2026-10-11', [], ['payable:loan']);

        // Skrytý druh by uložení odmítlo (kind_inactive); druhá strana skrytá není.
        self::assertSame('other', $out[0]['kind']);
        self::assertSame('loan', $out[1]['kind']);
    }

    public function testInstallmentsNotSummingToAmountOrNotAscendingAreFlagged(): void
    {
        $short = AiOtherItemNormalizer::normalize([self::item(['amount' => 30000.01])], self::CHART, '2026-10-11', []);
        self::assertFalse($short[0]['installments_valid'], 'součet splátek musí sedět na haléř');
        self::assertNull($short[0]['partner_id']);
        self::assertSame('Banka Test a.s.', $short[0]['partner_name']);

        $unordered = self::item();
        $unordered['installments'][1]['due_on'] = '2026-01-15';
        self::assertFalse(AiOtherItemNormalizer::normalize([$unordered], self::CHART, '2026-10-11', [])[0]['installments_valid']);
    }

    public function testInvalidItemsDropOrGetSafeDefaults(): void
    {
        $out = AiOtherItemNormalizer::normalize([
            self::item(['side' => 'both']),
            self::item(['amount' => 0]),
            self::item(['title' => ' ']),
            'není pole',
            self::item(['kind' => 'mortgage', 'issued_on' => 'včera', 'due_on' => '2020-01-01', 'installments' => [['due_on' => '2026-11-01', 'amount' => 30000]]]),
        ], self::CHART, '2026-10-11', []);

        self::assertCount(1, $out);
        self::assertSame('other', $out[0]['kind']);
        self::assertSame('2026-10-11', $out[0]['issued_on']);
        self::assertSame('2026-10-11', $out[0]['due_on'], 'splatnost nesmí předcházet vzniku');
        self::assertSame([], $out[0]['installments'], 'jediná splátka není kalendář');
        self::assertTrue($out[0]['installments_valid']);
    }
}
