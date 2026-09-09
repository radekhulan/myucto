<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Tax\Vat;

use MyInvoice\Service\Tax\Vat\EuAcquisitionTaxDate;
use MyInvoice\Service\Validation\PurchaseInvoiceValidation;
use PHPUnit\Framework\TestCase;

/**
 * § 25 ZDPH — DUZP pořízení zboží z JČS (audit VAT klasifikací 2026-08, nález M-7).
 *
 * Pravidlo samo je otestované u AI extraktoru; tenhle test hlídá to, co bylo rozbité:
 * že je pravidlo VOLATELNÉ mimo AI cestu a že se chybějící datum dodání NEDOMÝŠLÍ,
 * ale hlásí varováním.
 */
final class EuAcquisitionTaxDateTest extends TestCase
{
    public function testFifteenthOfNextMonthUnlessInvoiceIssuedEarlier(): void
    {
        // Doklad vystavený po 15. dni následujícího měsíce → DUZP = 15. den.
        self::assertSame('2026-05-15', EuAcquisitionTaxDate::for('2026-04-23', '2026-06-04'));
        // Vystavený dřív → DUZP = datum vystavení.
        self::assertSame('2026-05-02', EuAcquisitionTaxDate::for('2026-04-23', '2026-05-02'));
        // Prosincové dodání přetéká do ledna.
        self::assertSame('2027-01-15', EuAcquisitionTaxDate::for('2026-12-20', '2027-02-01'));
    }

    /** „Raději prázdný atribut a varování než odhadnutá hodnota." */
    public function testMissingDeliveryDateIsNotGuessed(): void
    {
        self::assertNull(EuAcquisitionTaxDate::for(null, '2026-06-04'));
        self::assertNull(EuAcquisitionTaxDate::for('', '2026-06-04'));
        self::assertNull(EuAcquisitionTaxDate::for('23.4.2026', '2026-06-04'));
    }

    /**
     * Pravidlo platí jen pro pořízení ZBOŽÍ z JČS (kód 23). Přijetí služby z JČS
     * (`24e`) se řídí § 24 a dovoz ze 3. země (`25`) § 23 — pustit na ně § 25 by
     * posunulo daň o půl měsíce u dokladů, kterých se netýká.
     */
    public function testAppliesOnlyToGoodsAcquisitionFromAnotherMemberState(): void
    {
        self::assertTrue(EuAcquisitionTaxDate::appliesTo(['vat_classification_code' => '23']));
        self::assertTrue(EuAcquisitionTaxDate::appliesTo([
            'items' => [['vat_classification_code' => '24e'], ['vat_classification_code' => '23']],
        ]));
        self::assertFalse(EuAcquisitionTaxDate::appliesTo(['vat_classification_code' => '24e']));
        self::assertFalse(EuAcquisitionTaxDate::appliesTo(['vat_classification_code' => '25']));
        self::assertFalse(EuAcquisitionTaxDate::appliesTo([]));
    }

    /**
     * Bez data dodání šlo § 25 dopočítat jen v AI cestě; ISDOC, iDoklad, Fakturoid ani
     * ruční zadání ho nedopočítaly a nikde ani slovo. Doklad proto musí varovat.
     */
    public function testMissingDeliveryDateOnEuAcquisitionRaisesWarning(): void
    {
        $warnings = PurchaseInvoiceValidation::warnings([
            'document_kind' => 'invoice',
            'issue_date' => '2026-06-04',
            'tax_date' => '2026-04-23',
            'vat_classification_code' => '23',
            'items' => [['vat_classification_code' => '23', 'vat_rate_snapshot' => 21.0]],
        ]);

        self::assertContains('eu_acquisition_delivery_date_missing', $warnings);
        self::assertNotContains('eu_acquisition_tax_date_mismatch', $warnings);
    }

    /** S vyplněným datem dodání se DUZP porovná — rozdíl je varování, ne tichý přepis. */
    public function testTaxDateNotMatchingSection25IsReported(): void
    {
        $base = [
            'document_kind' => 'invoice',
            'issue_date' => '2026-06-04',
            'delivery_date' => '2026-04-23',
            'vat_classification_code' => '23',
            'items' => [['vat_classification_code' => '23', 'vat_rate_snapshot' => 21.0]],
        ];

        $wrong = PurchaseInvoiceValidation::warnings($base + ['tax_date' => '2026-04-23']);
        self::assertContains('eu_acquisition_tax_date_mismatch', $wrong);

        $right = PurchaseInvoiceValidation::warnings($base + ['tax_date' => '2026-05-15']);
        self::assertNotContains('eu_acquisition_tax_date_mismatch', $right);
        self::assertNotContains('eu_acquisition_delivery_date_missing', $right);
    }

    /** Služba z JČS se stejnou konstelací dat varovat NESMÍ — § 25 na ni nedopadá. */
    public function testEuServiceIsNotSubjectToSection25(): void
    {
        $warnings = PurchaseInvoiceValidation::warnings([
            'document_kind' => 'invoice',
            'issue_date' => '2026-06-04',
            'tax_date' => '2026-04-23',
            'vat_classification_code' => '24e',
            'items' => [['vat_classification_code' => '24e', 'vat_rate_snapshot' => 21.0]],
        ]);

        self::assertNotContains('eu_acquisition_delivery_date_missing', $warnings);
    }
}
