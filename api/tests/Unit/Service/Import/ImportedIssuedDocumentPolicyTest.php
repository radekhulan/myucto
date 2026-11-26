<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\ImportedIssuedDocumentPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Kdy převzatý vydaný doklad musí zůstat konceptem: odečítá zálohy, nebo mu nesedí součet
 * řádků na doklad. Sdílené pravidlo obecného importu, AI importu a importu Shoptetu.
 */
final class ImportedIssuedDocumentPolicyTest extends TestCase
{
    /** @param array<string,mixed> $monetary @return array<string,mixed> */
    private static function inv(string $type, array $monetary, array $taxedDeposits = []): array
    {
        return ['invoice_type' => $type, 'monetary' => $monetary, 'taxed_deposits' => $taxedDeposits];
    }

    public function testOnlyTaxDocumentIsPaidByNature(): void
    {
        self::assertTrue(ImportedIssuedDocumentPolicy::isPaidByNature('tax_document'));
        foreach (['invoice', 'proforma', 'credit_note'] as $type) {
            self::assertFalse(ImportedIssuedDocumentPolicy::isPaidByNature($type), $type);
        }
        self::assertFalse(method_exists(ImportedIssuedDocumentPolicy::class, 'isInitiallyPaid'),
            'Odhad úhrady podle splatnosti se nevrací: uhrazeno je jen to, co nese evidence úhrady.');
    }

    public function testMatchingDocumentPasses(): void
    {
        $out = ImportedIssuedDocumentPolicy::assess(self::inv('invoice', ['total' => 1009.0, 'payable' => 1009.0, 'paid_deposits' => 0.0]), 1009.0);

        self::assertSame([], $out['review']);
        self::assertSame([], $out['notes']);
    }

    public function testRoundingWithinToleranceIsANoteOnly(): void
    {
        $out = ImportedIssuedDocumentPolicy::assess(self::inv('invoice', ['total' => 1008.6, 'payable' => 1009.0]), 1008.6);

        self::assertSame([], $out['review']);
        self::assertCount(1, $out['notes']);
        self::assertStringContainsString('zaokrouhlení dokladu 0,40', $out['notes'][0]);
    }

    public function testPaidDepositsForceDraft(): void
    {
        $out = ImportedIssuedDocumentPolicy::assess(self::inv('invoice', ['total' => 484.0, 'payable' => 0.0, 'paid_deposits' => 484.0]), 484.0);

        self::assertNotSame([], $out['review']);
        self::assertStringContainsString('odečítá zálohy', $out['review'][0]);
    }

    public function testTaxedDepositWithoutPaidAmountStillForcesDraft(): void
    {
        $out = ImportedIssuedDocumentPolicy::assess(
            self::inv('invoice', ['total' => 484.0, 'payable' => 484.0], [['id' => 'Z1', 'varsymbol' => '2026500001']]),
            484.0,
        );

        self::assertStringContainsString('2026500001', $out['review'][0]);
    }

    public function testPayableMismatchOverToleranceForcesDraft(): void
    {
        $out = ImportedIssuedDocumentPolicy::assess(self::inv('invoice', ['total' => null, 'payable' => 1015.0]), 1009.0);

        self::assertCount(1, $out['review']);
        self::assertStringContainsString('liší', $out['review'][0]);
    }

    public function testTaxDocumentIsNotComparedWithZeroPayable(): void
    {
        // DDPP dokumentuje úplatu, která už přišla: částka k úhradě 0 je správně.
        $out = ImportedIssuedDocumentPolicy::assess(self::inv('tax_document', ['total' => 484.0, 'payable' => 0.0]), 484.0);

        self::assertSame([], $out['review']);
    }

    public function testCreditNoteComparesMagnitudes(): void
    {
        $out = ImportedIssuedDocumentPolicy::assess(self::inv('credit_note', ['total' => 363.0, 'payable' => 363.0]), -363.0);

        self::assertSame([], $out['review']);
    }

    /** #289: Fakturovač vyplní PayableAmount nulou a zaokrouhlení nese v PayableRoundingAmount. */
    public function testZeroPayableWithoutDepositIsUnspecified(): void
    {
        $inv = self::inv('invoice', ['total' => 430.0, 'payable' => 0.0, 'paid_deposits' => 0.0, 'rounding' => -0.1]);
        $out = ImportedIssuedDocumentPolicy::assess($inv, 430.1);

        self::assertSame([], $out['review']);
        self::assertSame(-0.1, ImportedIssuedDocumentPolicy::payableRounding($inv, 430.1));
    }

    public function testRoundingDerivedFromPayableWhenElementMissing(): void
    {
        $inv = self::inv('invoice', ['total' => 1008.6, 'payable' => 1009.0]);

        self::assertSame(0.4, ImportedIssuedDocumentPolicy::payableRounding($inv, 1008.6));
    }

    public function testCreditNoteRoundingFromPositiveFileIsNegated(): void
    {
        // Soubor vede dobropis kladně, MyÚčto zápornými řádky.
        $inv = self::inv('credit_note', ['total' => 300.1, 'payable' => 300.0, 'rounding' => -0.1]);

        self::assertSame(0.1, ImportedIssuedDocumentPolicy::payableRounding($inv, -300.1));
        self::assertSame([], ImportedIssuedDocumentPolicy::assess($inv, -300.1)['review']);
    }

    public function testCreditNoteRoundingFromNegativeFileKeepsSign(): void
    {
        // Náš vlastní export ISDOC vede dobropis zápornými částkami.
        $inv = self::inv('credit_note', ['total' => -300.1, 'payable' => -300.0, 'rounding' => 0.1]);

        self::assertSame(0.1, ImportedIssuedDocumentPolicy::payableRounding($inv, -300.1));
    }

    public function testCreditNoteRoundingDerivedFromPayable(): void
    {
        $inv = self::inv('credit_note', ['total' => 300.1, 'payable' => 300.0]);

        self::assertSame(0.1, ImportedIssuedDocumentPolicy::payableRounding($inv, -300.1));
    }

    public function testRoundingOfWholeUnitOrMoreIsNotTaken(): void
    {
        self::assertSame(0.0, ImportedIssuedDocumentPolicy::payableRounding(self::inv('invoice', ['payable' => 1010.0]), 1008.6));
        self::assertSame(0.0, ImportedIssuedDocumentPolicy::payableRounding(self::inv('invoice', ['rounding' => -1.5]), 1008.6));
    }

    public function testDepositDifferenceIsNotRounding(): void
    {
        $inv = self::inv('invoice', ['total' => 484.0, 'payable' => 483.6, 'paid_deposits' => 0.4]);

        self::assertSame(0.0, ImportedIssuedDocumentPolicy::payableRounding($inv, 484.0));
    }

    public function testTaxDocumentAndProformaGetNoRounding(): void
    {
        self::assertSame(0.0, ImportedIssuedDocumentPolicy::payableRounding(self::inv('tax_document', ['payable' => 0.0, 'rounding' => -0.1]), 484.1));
        self::assertSame(0.0, ImportedIssuedDocumentPolicy::payableRounding(self::inv('proforma', ['payable' => 484.0]), 484.1));
    }

    public function testParserWithoutMonetaryDataIsNotJudged(): void
    {
        // Pohoda XML odpočet zálohy nese jako řádek, parser částky dokladu neposílá.
        self::assertSame(['review' => [], 'notes' => []], ImportedIssuedDocumentPolicy::assess(['invoice_type' => 'invoice'], 100.0));
    }
}
