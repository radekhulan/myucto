<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Service\Accounting\Bank\BankMessageNormalizer;
use MyInvoice\Service\Accounting\Bank\BankRuleMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Pure jednotkové testy match logiky pravidel (§3.5, §4.1) — bez DB.
 * Kritéria = AND přes vyplněná pole; normalizace zprávy (diakritika, číslice,
 * whitespace); ABS(amount) v uzavřeném intervalu; fragment substring.
 */
final class BankRuleMatcherTest extends TestCase
{
    private BankRuleMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new BankRuleMatcher();
    }

    /** @param array<string,mixed> $over */
    private function rule(array $over = []): array
    {
        return array_merge([
            'counterparty_account' => null, 'counterparty_bank' => null,
            'counterparty_prefix' => null,
            'variable_symbol' => null, 'message_contains' => null,
            'amount_min' => null, 'amount_max' => null,
        ], $over);
    }

    /** @param array<string,mixed> $over */
    private function tx(array $over = []): array
    {
        return array_merge([
            'amount' => 1000.0, 'variable_symbol' => null,
            'counterparty_account' => null, 'counterparty_bank' => null, 'description' => null,
            'counterparty_name' => null,
        ], $over);
    }

    public function testAllCriteriaAndMustHold(): void
    {
        $rule = $this->rule([
            'counterparty_account' => '77621', 'variable_symbol' => '1234',
            'message_contains' => BankMessageNormalizer::normalize('OSSZ'),
            'amount_min' => 20000.0, 'amount_max' => 30000.0,
        ]);
        $ok = $this->tx([
            'amount' => -24836.0, 'variable_symbol' => '1234',
            'counterparty_account' => '77621', 'description' => 'Odvod OSSZ 05/2026',
        ]);
        self::assertTrue($this->matcher->matching($rule, $ok));

        // jedno kritérium mimo → false (VS jiné)
        self::assertFalse($this->matcher->matching($rule, array_merge($ok, ['variable_symbol' => '9999'])));
        // účet jiný → false
        self::assertFalse($this->matcher->matching($rule, array_merge($ok, ['counterparty_account' => '999'])));
        // částka mimo interval → false
        self::assertFalse($this->matcher->matching($rule, array_merge($ok, ['amount' => -100.0])));
    }

    public function testMessageNormalizationIgnoresDigitsAndDiacritics(): void
    {
        // Číslice se odstraňují — „Odvod OSSZ 05/2026" ≈ „…06/2026".
        $fragment = BankMessageNormalizer::normalize('Odvod OSSZ');
        self::assertSame('odvod ossz', $fragment);

        $rule = $this->rule(['message_contains' => $fragment]);
        self::assertTrue($this->matcher->matching($rule, $this->tx(['description' => 'Odvod OSSZ 05/2026'])));
        self::assertTrue($this->matcher->matching($rule, $this->tx(['description' => 'ODVOD OSSZ 06/2026'])));
        self::assertFalse($this->matcher->matching($rule, $this->tx(['description' => 'Poplatek banka'])));

        // Diakritika i číslice pryč, výstup je lowercase ASCII bez číslic.
        $norm = BankMessageNormalizer::normalize('Přeplatek 2026');
        self::assertMatchesRegularExpression('/^[a-z ]+$/', $norm);
        self::assertStringNotContainsString('ř', $norm);
    }

    public function testMessageFragmentAlsoMatchesCounterpartyName(): void
    {
        // §7: fragment se hledá i ve jméně protistrany (název bývá jen v counterparty_name).
        $rule = $this->rule(['message_contains' => BankMessageNormalizer::normalize('Ukazka')]);
        self::assertTrue($this->matcher->matching(
            $rule,
            $this->tx(['description' => 'Prevod', 'counterparty_name' => 'UKAZKA GROUP s.r.o.']),
        ));
        // Ani v popisu, ani ve jméně → false.
        self::assertFalse($this->matcher->matching(
            $rule,
            $this->tx(['description' => 'Prevod', 'counterparty_name' => 'Jina firma']),
        ));
        // Fragment jen v popisu stále platí.
        self::assertTrue($this->matcher->matching(
            $rule,
            $this->tx(['description' => 'Platba ukazka', 'counterparty_name' => null]),
        ));
    }

    public function testAmountBandIsClosedIntervalOnAbs(): void
    {
        $rule = $this->rule(['amount_min' => 100.0, 'amount_max' => 200.0]);
        self::assertTrue($this->matcher->matching($rule, $this->tx(['amount' => 100.0])));   // dolní hranice včetně
        self::assertTrue($this->matcher->matching($rule, $this->tx(['amount' => -200.0])));  // horní hranice, ABS
        self::assertFalse($this->matcher->matching($rule, $this->tx(['amount' => 99.99])));
        self::assertFalse($this->matcher->matching($rule, $this->tx(['amount' => 200.01])));
    }

    public function testMissingAmountBoundaryMeansNoLimitOnThatSide(): void
    {
        $onlyMaximum = $this->rule(['amount_min' => null, 'amount_max' => 200.0]);
        self::assertTrue($this->matcher->matching($onlyMaximum, $this->tx(['amount' => 0.01])));
        self::assertTrue($this->matcher->matching($onlyMaximum, $this->tx(['amount' => -200.0])));
        self::assertFalse($this->matcher->matching($onlyMaximum, $this->tx(['amount' => 200.01])));

        $onlyMinimum = $this->rule(['amount_min' => 100.0, 'amount_max' => null]);
        self::assertFalse($this->matcher->matching($onlyMinimum, $this->tx(['amount' => 99.99])));
        self::assertTrue($this->matcher->matching($onlyMinimum, $this->tx(['amount' => -100.0])));
        self::assertTrue($this->matcher->matching($onlyMinimum, $this->tx(['amount' => 999999.0])));
    }

    public function testEmptyRuleFieldsAreIgnored(): void
    {
        // Jen VS kritérium; ostatní pole prázdná → ignorují se.
        $rule = $this->rule(['variable_symbol' => '7712']);
        self::assertTrue($this->matcher->matching($rule, $this->tx(['variable_symbol' => '7712', 'amount' => 5.0])));
        self::assertFalse($this->matcher->matching($rule, $this->tx(['variable_symbol' => null])));
    }

    public function testCounterpartyBankNarrowsMatch(): void
    {
        $rule = $this->rule(['counterparty_account' => '77621', 'counterparty_bank' => '0710']);
        self::assertTrue($this->matcher->matching($rule, $this->tx(['counterparty_account' => '77621', 'counterparty_bank' => '0710'])));
        self::assertFalse($this->matcher->matching($rule, $this->tx(['counterparty_account' => '77621', 'counterparty_bank' => '0800'])));
    }

    public function testCounterpartyBankMatchesWithoutSpecificAccount(): void
    {
        $rule = $this->rule(['counterparty_bank' => '0710']);
        self::assertTrue($this->matcher->matching($rule, $this->tx(['counterparty_bank' => '0710'])));
        self::assertFalse($this->matcher->matching($rule, $this->tx(['counterparty_bank' => '0800'])));
    }

    public function testCounterpartyPrefixMatchesRawAccount(): void
    {
        $rule = $this->rule(['counterparty_prefix' => '705']);
        self::assertTrue($this->matcher->matching($rule, $this->tx(['counterparty_account' => '705-77628031'])));
        self::assertFalse($this->matcher->matching($rule, $this->tx(['counterparty_account' => '721-77628031'])));
    }

    public function testOwnBankAccountMustMatchWhenRuleHasIt(): void
    {
        $rule = $this->rule(['message_contains' => BankMessageNormalizer::normalize('úrok'), 'bank_account_id' => 7]);
        $tx = $this->tx(['description' => 'Úrok']);
        self::assertTrue($this->matcher->matching($rule, $tx + [BankRuleMatcher::OWN_ACCOUNT => 7]));
        self::assertFalse($this->matcher->matching($rule, $tx + [BankRuleMatcher::OWN_ACCOUNT => 8]), 'Pohyb jiného účtu');
        self::assertFalse($this->matcher->matching($rule, $tx + [BankRuleMatcher::OWN_ACCOUNT => null]), 'Výpis bez dohledaného účtu');
        self::assertFalse($this->matcher->matching($rule, $tx), 'Volající, který účet nezná');
        self::assertTrue($this->matcher->matching(['bank_account_id' => null] + $rule, $tx), 'Obecné pravidlo účet nepotřebuje');
    }

    public function testRankPrefersPriorityThenAccountRestriction(): void
    {
        $general = ['priority' => 100, 'bank_account_id' => null];
        $account = ['priority' => 100, 'bank_account_id' => 3];
        self::assertTrue(BankRuleMatcher::rank($account) < BankRuleMatcher::rank($general));
        self::assertTrue(BankRuleMatcher::rank(['priority' => 40] + $general) < BankRuleMatcher::rank($account), 'Priorita rozhoduje první');
        self::assertSame(BankRuleMatcher::rank($account), BankRuleMatcher::rank(['bank_account_id' => 9] + $account), 'Dva účty stejné priority jsou souběh');
    }
}
