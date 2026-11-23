<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Bank;

use MyInvoice\Service\Accounting\Bank\BankPostingRuleValidator;
use MyInvoice\Service\Accounting\Bank\BankRuleMatcher;
use MyInvoice\Service\Accounting\PostingException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Podmínka platby kartou v pravidle účtování a validace přednosti pravidla před
 * párováním s doklady. Bez DB.
 */
final class BankRuleCardAndSkipMatchingTest extends TestCase
{
    /** @param array<string,mixed> $over */
    private static function rule(array $over = []): array
    {
        return $over + [
            'counterparty_account' => null, 'counterparty_bank' => null, 'counterparty_prefix' => null,
            'variable_symbol' => null, 'message_contains' => 'shell',
            'amount_min' => null, 'amount_max' => null, 'card_payment' => null,
        ];
    }

    /** @param array<string,mixed> $over */
    private static function tx(array $over = []): array
    {
        return $over + [
            'amount' => -850.0, 'variable_symbol' => null, 'counterparty_account' => null,
            'counterparty_bank' => null, 'description' => 'SHELL PRAHA 4', 'counterparty_name' => null,
            'card_last4' => null,
        ];
    }

    public function testCardOnlyRuleMatchesCardPaymentAndNotTransferWithSameText(): void
    {
        $matcher = new BankRuleMatcher();
        $rule = self::rule(['card_payment' => 1]);

        self::assertTrue($matcher->matching($rule, self::tx(['card_last4' => '4242'])));
        self::assertFalse($matcher->matching($rule, self::tx()), 'Převod se stejným textem není platba kartou.');
        self::assertFalse($matcher->matching($rule, self::tx(['card_last4' => '42'])), 'Neplatná koncovka není karta.');
    }

    public function testNonCardRuleSkipsCardPayments(): void
    {
        $matcher = new BankRuleMatcher();
        $rule = self::rule(['card_payment' => 0]);

        self::assertTrue($matcher->matching($rule, self::tx()));
        self::assertFalse($matcher->matching($rule, self::tx(['card_last4' => '4242'])));
    }

    public function testRuleWithoutCardConditionMatchesBoth(): void
    {
        $matcher = new BankRuleMatcher();
        $rule = self::rule();
        unset($rule['card_payment']);

        self::assertTrue($matcher->matching($rule, self::tx()));
        self::assertTrue($matcher->matching($rule, self::tx(['card_last4' => '4242'])));
    }

    public function testCardPredicateReusesValidLast4(): void
    {
        self::assertTrue(BankRuleMatcher::isCardPayment(['card_last4' => '0007']));
        self::assertTrue(BankRuleMatcher::isCardPayment(['card_last4' => ' 0007 ']));
        self::assertFalse(BankRuleMatcher::isCardPayment(['card_last4' => '']));
        self::assertFalse(BankRuleMatcher::isCardPayment(['card_last4' => 'ABCD']));
        self::assertFalse(BankRuleMatcher::isCardPayment([]));
    }

    public function testTxFieldsCarryCardLast4(): void
    {
        $fields = BankRuleMatcher::txFields(['amount' => '-10.50', 'card_last4' => '1234', 'variable_symbol' => 77]);

        self::assertSame(-10.5, $fields['amount']);
        self::assertSame('1234', $fields['card_last4']);
        self::assertSame('77', $fields['variable_symbol']);
        self::assertNull($fields['counterparty_account']);
    }

    public function testCardOnlyConditionIsEnoughAsCriterion(): void
    {
        BankPostingRuleValidator::assertMatchingConditions(self::rule(['message_contains' => null, 'card_payment' => 1]));
        $this->addToAssertionCount(1);
    }

    public function testNonCardConditionAloneIsNotACriterion(): void
    {
        $this->expectException(PostingException::class);
        $this->expectExceptionMessage('alespoň jedno kritérium');
        BankPostingRuleValidator::assertMatchingConditions(self::rule(['message_contains' => null, 'card_payment' => 0]));
    }

    public function testSkipMatchingRequiresCounterpartyAccountAndBank(): void
    {
        BankPostingRuleValidator::assertMatchingConditions(self::rule([
            'counterparty_account' => '1000000005', 'counterparty_bank' => '0100', 'skip_document_matching' => 1,
        ]));
        $this->addToAssertionCount(1);

        foreach ([
            ['counterparty_account' => '1000000005', 'counterparty_bank' => null],
            ['counterparty_account' => null, 'counterparty_bank' => '0100'],
            ['counterparty_account' => null, 'counterparty_bank' => null, 'card_payment' => 1],
        ] as $over) {
            try {
                BankPostingRuleValidator::assertMatchingConditions(self::rule($over + ['skip_document_matching' => 1]));
                self::fail('Přednost před párováním bez účtu a banky musí validace odmítnout.');
            } catch (PostingException $e) {
                self::assertSame('rule_skip_matching_requires_account', $e->errorCode);
                self::assertSame(422, $e->httpStatus);
            }
        }
    }

    /** @return iterable<string,array{mixed,?int}> */
    public static function cardValues(): iterable
    {
        yield 'null' => [null, null];
        yield 'empty' => ['', null];
        yield 'one' => [1, 1];
        yield 'one string' => ['1', 1];
        yield 'true' => [true, 1];
        yield 'zero' => [0, 0];
        yield 'zero string' => ['0', 0];
        yield 'false' => [false, 0];
    }

    #[DataProvider('cardValues')]
    public function testCardPaymentNormalization(mixed $raw, ?int $expected): void
    {
        self::assertSame($expected, BankPostingRuleValidator::cardPayment($raw));
    }

    public function testInvalidCardPaymentIsValidationError(): void
    {
        try {
            BankPostingRuleValidator::cardPayment('2');
            self::fail('Hodnota 2 není platná podmínka karty.');
        } catch (PostingException $e) {
            self::assertSame('invalid_card_payment', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }

    public function testSkipDocumentMatchingFlag(): void
    {
        self::assertSame(1, BankPostingRuleValidator::skipDocumentMatching(true));
        self::assertSame(1, BankPostingRuleValidator::skipDocumentMatching('1'));
        self::assertSame(0, BankPostingRuleValidator::skipDocumentMatching(null));
        self::assertSame(0, BankPostingRuleValidator::skipDocumentMatching('0'));
        self::assertSame(0, BankPostingRuleValidator::skipDocumentMatching('nesmysl'));
    }
}
