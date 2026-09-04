<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\Setup\AccountingRuleEquivalence;
use PHPUnit\Framework\TestCase;

final class AccountingRuleEquivalenceTest extends TestCase
{
    public function testExpenseRuleIgnoresPresentationButNormalizesMatchingText(): void
    {
        self::assertTrue(AccountingRuleEquivalence::expense([
            'name' => 'Starý název',
            'description_contains' => 'Pohonné hmoty',
            'expense_kind' => 'material',
            'target_account_code' => '501.100',
            'application_mode' => 'auto',
            'priority' => 20,
        ], [
            'name' => 'Nový název',
            'description_contains' => 'pohonne hmoty',
            'expense_kind' => 'material',
            'target_account_code' => '501.100',
            'application_mode' => 'suggest',
            'priority' => 100,
        ]));
    }

    public function testChangedExpenseTargetIsNotEquivalent(): void
    {
        self::assertFalse(AccountingRuleEquivalence::expense([
            'description_contains' => 'cloud',
            'expense_kind' => 'service',
            'target_account_code' => '518',
        ], [
            'description_contains' => 'cloud',
            'expense_kind' => 'service',
            'target_account_code' => '518.100',
        ]));
    }

    public function testBankRuleNormalizesAccountBankAndMessage(): void
    {
        self::assertTrue(AccountingRuleEquivalence::bank([
            'direction' => 'outgoing',
            'counterparty_account' => '19-1000000005/0100',
            'counterparty_bank' => '0100',
            'message_contains' => 'Měsíční poplatek',
            'amount_min' => null,
            'amount_max' => null,
            'debit_account_code' => '568',
            'credit_account_code' => '221',
            'operation_type' => null,
            'applies_currency' => 'CZK',
        ], [
            'direction' => 'outgoing',
            'counterparty_account' => '19-1000000005',
            'counterparty_bank' => '100',
            'message_contains' => 'mesicni poplatek',
            'amount_min' => 100.0,
            'amount_max' => 100.0,
            'debit_account_code' => '568',
            'credit_account_code' => '221',
            'operation_type' => 'bank.rule.custom',
            'applies_currency' => 'czk',
        ]));
    }
}
