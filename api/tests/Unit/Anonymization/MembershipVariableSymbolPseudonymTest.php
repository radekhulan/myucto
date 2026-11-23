<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Anonymization;

use MyInvoice\Service\Anonymization\AnonymizationPolicy;
use MyInvoice\Service\Anonymization\Pseudonymizer;
use MyInvoice\Service\Anonymization\TextScrubber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * VS členského předpisu a VS bankovního pohybu musí po pseudonymizaci zůstat
 * spárovatelné (stejný klíč bez vodicích nul) a zároveň nesmí prozradit původní symbol.
 * Pořadí odpovídá fázím anonymizace: předpis ve strukturovaném průchodu, banka v odvozeném.
 */
final class MembershipVariableSymbolPseudonymTest extends TestCase
{
    #[DataProvider('symbols')]
    public function testClaimAndBankSymbolsKeepTheSameMatchKey(string $claim, string $bank): void
    {
        $claimStrategy = AnonymizationPolicy::COLUMNS['nonprofit_membership_charge_cases']['variable_symbol'];
        $bankStrategy = AnonymizationPolicy::COLUMNS['bank_transactions']['variable_symbol'];
        self::assertContains($claimStrategy, AnonymizationPolicy::PHASE_STRUCTURED);
        self::assertContains($bankStrategy, AnonymizationPolicy::PHASE_DERIVED);

        $scrubber = new TextScrubber(new Pseudonymizer('synthetic-membership-symbol'));
        $claimPseudo = $scrubber->byStrategy($claimStrategy, $claim);
        $bankPseudo = $scrubber->byStrategy($bankStrategy, $bank);

        self::assertMatchesRegularExpression('/^[0-9]{1,10}$/D', $claimPseudo);
        self::assertSame(strlen($claim), strlen($claimPseudo));
        if (strlen(ltrim($claim, '0')) >= 6) {
            self::assertNotSame(ltrim($claim, '0'), ltrim($claimPseudo, '0'), 'Pseudonym nesmí nést původní symbol.');
        } else {
            self::assertSame($claim, $claimPseudo, 'Krátký symbol neidentifikuje a zůstává jako u shapeDigits.');
        }
        self::assertSame(strlen($bank), strlen($bankPseudo));
        self::assertSame(ltrim($claimPseudo, '0'), ltrim($bankPseudo, '0'), 'Předpis a platba se po anonymizaci musí dál najít podle VS.');
    }

    public static function symbols(): iterable
    {
        yield 'dlouhý shodný' => ['0012345678', '0012345678'];
        yield 'předpis s nulami, banka bez nich' => ['0012345678', '12345678'];
        yield 'předpis bez nul, banka s nulami' => ['12345678', '0012345678'];
        yield 'krátký shodný' => ['123', '123'];
        yield 'krátký s nulami v předpisu' => ['00123', '123'];
        yield 'krátký s nulami v bance' => ['123', '0000000123'];
        yield 'jednociferný' => ['7', '07'];
    }

    public function testCommandRequestSymbolUsesTheSamePseudonymAsTheCase(): void
    {
        $strategy = AnonymizationPolicy::COLUMNS['nonprofit_membership_charge_cases']['variable_symbol'];
        $scrubber = new TextScrubber(new Pseudonymizer('synthetic-membership-symbol'));
        $case = $scrubber->byStrategy($strategy, '0012345678');
        self::assertSame($case, $scrubber->byStrategy($strategy, '0012345678'));
        self::assertSame(ltrim($case, '0'), ltrim($scrubber->byStrategy($strategy, '12345678'), '0'));
    }

    public function testShortMemberSymbolDoesNotRewriteUnrelatedSymbolsInTheCopy(): void
    {
        $scrubber = new TextScrubber(new Pseudonymizer('synthetic-membership-symbol'));
        $scrubber->byStrategy(AnonymizationPolicy::COLUMNS['nonprofit_membership_charge_cases']['variable_symbol'], '123');
        // Úhrada faktury se stejným krátkým VS: faktura svůj VS v kopii drží, úhrada ani SS se nesmí změnit.
        self::assertSame('123', $scrubber->byStrategy(AnonymizationPolicy::COLUMNS['invoice_payments']['variable_symbol'], '123'));
        self::assertSame('0123', $scrubber->byStrategy(AnonymizationPolicy::COLUMNS['bank_transactions']['specific_symbol'], '0123'));
    }

    public function testInvoiceWithTheSameLongSymbolStaysMatchableWithItsPayment(): void
    {
        $scrubber = new TextScrubber(new Pseudonymizer('synthetic-membership-symbol'));
        $scrubber->byStrategy(AnonymizationPolicy::COLUMNS['nonprofit_membership_charge_cases']['variable_symbol'], '2026001');
        // Faktura má náhodou týž VS jako členský předpis. VS faktury i pohybu jdou přes stejnou
        // strategii, takže se přemapují shodně a úhrada faktury v kopii zůstane spárovatelná.
        $invoice = $scrubber->byStrategy(AnonymizationPolicy::COLUMNS['invoices']['varsymbol'], '2026001');
        $payment = $scrubber->byStrategy(AnonymizationPolicy::COLUMNS['bank_transactions']['variable_symbol'], '002026001');
        self::assertSame(ltrim($invoice, '0'), ltrim($payment, '0'));
        self::assertSame($invoice, $scrubber->byStrategy(AnonymizationPolicy::COLUMNS['invoice_payments']['variable_symbol'], '2026001'));
    }

    public function testDifferentSymbolsDoNotCollide(): void
    {
        $strategy = AnonymizationPolicy::COLUMNS['nonprofit_membership_charge_cases']['variable_symbol'];
        $scrubber = new TextScrubber(new Pseudonymizer('synthetic-membership-symbol'));
        $seen = [];
        foreach (range(100000, 100999) as $number) {
            $seen[ltrim($scrubber->byStrategy($strategy, (string) $number), '0')] = true;
        }
        self::assertCount(1000, $seen, 'Dva různé symboly nesmí po pseudonymizaci splynout v jednoho kandidáta.');
    }
}
