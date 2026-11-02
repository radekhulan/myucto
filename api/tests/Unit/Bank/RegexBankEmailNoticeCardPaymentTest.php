<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeProvider;
use MyInvoice\Service\Bank\EmailNotice\Parser\RegexBankEmailNoticeParser;
use PHPUnit\Framework\TestCase;

/**
 * Avízo o platbě kartou nemá znaménko, VS ani slovo o směru, a přitom je vždy výdajem
 * (issue 282). Vzory jsou ty, které doporučuje manuál a odpověď v issue.
 */
final class RegexBankEmailNoticeCardPaymentTest extends TestCase
{
    private const BODY = 'Dobrý den, potvrzujeme platbu kartou 1234 ve výši 1 113,04 Kč ze dne 23. 10. 2026 '
        . 'u PAYPAL *EXAMPLE. Na účtu 1000000005 je k dispozici 25 000,50 Kč.';

    public function testCardPaymentIsExpenseWithoutVariableSymbol(): void
    {
        $parsed = (new RegexBankEmailNoticeParser())->parse(
            new BankEmailNoticeMessage(
                uid: 1,
                messageId: '<card@example.com>',
                date: new \DateTimeImmutable('2026-10-24 09:30:00'),
                sender: 'avizo@example.com',
                subject: 'Platba kartou',
                text: self::BODY,
                raw: self::BODY,
                authResults: [],
                allowForwarded: false,
                forwardedFrom: '',
            ),
            new BankEmailNoticeProvider(
                id: 1,
                supplierId: 1,
                providerRef: 'db:1',
                code: 'karta',
                name: 'Platba kartou',
                parserType: 'regex',
                enabled: true,
                senderWhitelist: 'example.com',
                subjectPattern: null,
                bodyPattern: 'potvrzujeme\s+platbu\s+kartou',
                fieldPatterns: [
                    'amount' => 've výši\s+(?<value>[0-9 ]+,[0-9]{2})',
                    'currency' => 've výši\s+[0-9 ]+,[0-9]{2}\s*(?<value>Kč|CZK|EUR|USD)',
                    'posted_at' => 'ze dne\s+(?<value>[0-9]{1,2}\.\s*[0-9]{1,2}\.\s*[0-9]{4})',
                    'recipient_account' => 'Na účtu\s+(?<value>[0-9\-]+(?:/[0-9]{4})?)',
                    'direction' => '(?<value>platbu\s+kartou)',
                    'counterparty_name' => '[0-9]{4}\s+u\s+(?<value>.+?)\.\s+Na účtu',
                    'balance' => 'k dispozici\s+(?<value>[0-9 ]+,[0-9]{2})',
                ],
                normalizerConfig: [],
                system: false,
            ),
        );

        self::assertSame(-1113.04, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('2026-10-23', $parsed->postedAt);
        self::assertSame('1000000005', $parsed->recipientAccount);
        self::assertSame('PAYPAL *EXAMPLE', $parsed->counterpartyName);
        self::assertSame(25000.5, $parsed->balance);
        self::assertSame('', $parsed->variableSymbol);
    }
}
