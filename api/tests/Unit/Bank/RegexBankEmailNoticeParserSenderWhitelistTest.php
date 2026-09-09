<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeProvider;
use MyInvoice\Service\Bank\EmailNotice\Parser\RegexBankEmailNoticeParser;
use PHPUnit\Framework\TestCase;

/**
 * Regrese k security reportu R3: regex provider BEZ whitelistu odesílatele
 * pouštěl dřív cokoliv. Seedovaný globální provider ČS ho neměl vyplněný, takže
 * stačilo poslat do sledované schránky e-mail s veřejně opsatelnými údaji
 * (číslo účtu a VS z faktury) a faktura se označila jako zaplacená.
 * `senderAllowed()` je proto fail-closed: prázdný whitelist nepustí NIC.
 */
final class RegexBankEmailNoticeParserSenderWhitelistTest extends TestCase
{
    /**
     * Tělo, které projde seedovaným `body_pattern` „Směr\s+platby" i všemi
     * povinnými field patterny — tj. přesně to, co útočník umí napsat sám.
     */
    private const SPOOFED_BODY = <<<TEXT
    Informace o transakci

    Směr platby: příchozí
    Číslo účtu: 6509175329/0800
    Číslo účtu protistrany: 2801836907/2010
    Částka v měně účtu: 10 000,00 Kč
    Variabilní symbol: 123456789
    TEXT;

    /**
     * @return array<string,string>
     */
    private function fieldPatterns(): array
    {
        return [
            'recipient_account' => 'Číslo účtu:\s*(?<value>[0-9\-]+\/[0-9]{4})',
            'counterparty_account' => 'Číslo účtu protistrany:\s*(?<value>[0-9\-]+\/[0-9]{4})',
            'amount' => 'Částka v měně účtu:\s*(?<value>[0-9 .]+,[0-9]{2})',
            'currency' => 'Částka v měně účtu:\s*[0-9 .]+,[0-9]{2}\s*(?<value>Kč|CZK|EUR|USD|€)',
            'variable_symbol' => 'Variabilní symbol:\s*(?<value>[0-9]+)',
            'direction' => 'Směr platby:\s*(?<value>příchozí|odchozí)',
        ];
    }

    private function provider(?string $whitelist): BankEmailNoticeProvider
    {
        return new BankEmailNoticeProvider(
            id: 2,
            supplierId: null,
            providerRef: 'db:2',
            code: 'ceska-sporitelna',
            name: 'Česká spořitelna - avízo o pohybu',
            parserType: 'regex',
            enabled: true,
            senderWhitelist: $whitelist,
            subjectPattern: null,
            bodyPattern: 'Směr\s+platby',
            fieldPatterns: $this->fieldPatterns(),
            normalizerConfig: [],
            system: false,
        );
    }

    private function message(string $sender, bool $allowForwarded = false, string $forwardedFrom = ''): BankEmailNoticeMessage
    {
        return new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<spoof@example.com>',
            date: new \DateTimeImmutable('2026-08-04 09:30:00'),
            sender: $sender,
            subject: 'Avízo o pohybu na účtu',
            text: self::SPOOFED_BODY,
            raw: self::SPOOFED_BODY,
            authResults: [],
            allowForwarded: $allowForwarded,
            forwardedFrom: $forwardedFrom,
        );
    }

    public function testProviderWithoutWhitelistRejectsEverything(): void
    {
        $parser = new RegexBankEmailNoticeParser();

        foreach ([null, '', '   ', " ,;\n"] as $whitelist) {
            $provider = $this->provider($whitelist);
            self::assertFalse(
                $parser->supports($this->message('attacker@evil.example'), $provider),
                'Prázdný whitelist nesmí pustit cizího odesílatele.',
            );
            // Ani odesílatel, který se tváří jako banka, nesmí projít bez whitelistu.
            self::assertFalse(
                $parser->supports($this->message('Česká spořitelna <automat@csas.cz>'), $provider),
            );
        }
    }

    public function testProviderWithoutWhitelistRejectsForwardedNoticeToo(): void
    {
        $parser = new RegexBankEmailNoticeParser();

        self::assertFalse($parser->supports(
            $this->message('Jan Novák <jan.novak@example.com>', true),
            $this->provider(null),
        ));
        self::assertFalse($parser->supports(
            $this->message('Jan Novák <jan.novak@example.com>', true, 'example.com'),
            $this->provider(''),
        ));
    }

    public function testExactAddressWhitelistMatchesOnlyThatSender(): void
    {
        $parser = new RegexBankEmailNoticeParser();
        $provider = $this->provider('automat@csas.cz');

        self::assertTrue($parser->supports($this->message('automat@csas.cz'), $provider));
        self::assertTrue($parser->supports($this->message('Česká spořitelna <automat@csas.cz>'), $provider));
        self::assertFalse($parser->supports($this->message('attacker@evil.example'), $provider));
        self::assertFalse($parser->supports($this->message('automat@csas.cz.evil.example'), $provider));
    }

    public function testExactAddressWhitelistRejectsAddressHiddenInDisplayName(): void
    {
        $parser = new RegexBankEmailNoticeParser();
        $provider = $this->provider('noreply@csob.cz');

        self::assertFalse($parser->supports(
            $this->message('"noreply@csob.cz <noreply@csob.cz>" <attacker@evil.example>'),
            $provider,
        ));
    }

    /**
     * Bez domén by fail-closed whitelist nešel u bank rozesílajících z více adres
     * rozumně vyplnit. Položka bez „@" proto matchuje doménu včetně subdomén —
     * end-anchored (SenderDomain), takže `csas.cz.evil.example` neprojde.
     */
    public function testDomainWhitelistEntryMatchesSubdomainsButNotSuffixTricks(): void
    {
        $parser = new RegexBankEmailNoticeParser();
        $provider = $this->provider('csas.cz');

        self::assertTrue($parser->supports($this->message('automat@csas.cz'), $provider));
        self::assertTrue($parser->supports($this->message('Avízo <noreply@mail.csas.cz>'), $provider));
        self::assertFalse($parser->supports($this->message('automat@csas.cz.evil.example'), $provider));
        self::assertFalse($parser->supports($this->message('automat@evil.example'), $provider));
    }

    public function testWhitelistSeparatorsAndCaseAreTolerated(): void
    {
        $parser = new RegexBankEmailNoticeParser();
        $provider = $this->provider("Automat@CSAS.cz; info@rb.cz,\n noreply@fio.cz");

        self::assertTrue($parser->supports($this->message('AUTOMAT@csas.cz'), $provider));
        self::assertTrue($parser->supports($this->message('Fio banka <noreply@fio.cz>'), $provider));
        self::assertFalse($parser->supports($this->message('noreply@fio.cz.evil.example'), $provider));
    }
}
