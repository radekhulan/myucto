<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\Revenue\RevenueRuleMatcher;
use MyInvoice\Service\Accounting\SettlementAccounts;
use PHPUnit\Framework\TestCase;

/**
 * Vyhodnocení pravidel výnosů bez DB: AND přes vyplněná kritéria, první shoda podle
 * pořadí vyhrává, kód bez ohledu na velikost písmen, text bez diakritiky.
 */
final class RevenueRuleMatcherTest extends TestCase
{
    public function testAllFilledCriteriaMustMatch(): void
    {
        $rules = [
            ['id' => 1, 'item_code' => 'SKU-1', 'vat_rate' => 12.0, 'target_account_code' => '604'],
            ['id' => 2, 'item_code' => 'SKU-1', 'target_account_code' => '601'],
        ];

        self::assertSame(1, RevenueRuleMatcher::firstMatch($rules, 'sku-1', 'Zboží', 12.0)['id']);
        self::assertSame(2, RevenueRuleMatcher::firstMatch($rules, 'SKU-1', 'Zboží', 21.0)['id'], 'Sazba nesedí, bere se další pravidlo.');
        self::assertNull(RevenueRuleMatcher::firstMatch($rules, null, 'Zboží', 12.0), 'Položka bez kódu na pravidlo s kódem nesedí.');
    }

    public function testDescriptionIgnoresCaseAndDiacritics(): void
    {
        $rules = [['id' => 7, 'description_contains' => 'Doprava', 'target_account_code' => '378']];

        self::assertSame(7, RevenueRuleMatcher::firstMatch($rules, null, 'DOPRAVA: výdejní místo', 21.0)['id']);
        self::assertSame(7, RevenueRuleMatcher::firstMatch($rules, null, 'Přeprava a dopráva', 21.0)['id']);
        self::assertNull(RevenueRuleMatcher::firstMatch($rules, null, 'Balné', 21.0));
    }

    public function testInactiveAndEmptyRulesNeverMatch(): void
    {
        $rules = [
            ['id' => 1, 'vat_rate' => 21.0, 'is_active' => false, 'target_account_code' => '604'],
            ['id' => 2, 'item_code' => '', 'description_contains' => null, 'vat_rate' => null, 'target_account_code' => '602'],
        ];

        self::assertNull(RevenueRuleMatcher::firstMatch($rules, 'X', 'Cokoli', 21.0));
    }

    public function testSettlementAccountsShareOnePrefixList(): void
    {
        foreach (['311', '311.100', '324', '343', '345', '221001', '211', '321', '325', '331'] as $code) {
            self::assertTrue(SettlementAccounts::isSettlement($code), $code);
        }
        foreach (['315', '378', '395', '518', '604', '641'] as $code) {
            self::assertFalse(SettlementAccounts::isSettlement($code), $code);
        }
        self::assertSame('^(311|321|314|324|325|33|34|221|211)', SettlementAccounts::sqlRegexp());
    }
}
