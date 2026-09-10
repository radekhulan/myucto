<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Document\AttachmentCheck;

use MyInvoice\Service\Document\AttachmentCheck\AttachmentComparator;
use PHPUnit\Framework\TestCase;

/**
 * Porovnání zaúčtovaného dokladu s vytěžením přílohy. Syntetická data; hlídá hlavně
 * to, co se NESMÍ hlásit (zaokrouhlení, zálohy, dobropisy, cizí měna, chybějící pole),
 * protože kontrola, která křičí u každého druhého dokladu, se přestane číst.
 */
final class AttachmentComparatorTest extends TestCase
{
    public function testTaxDateInOtherMonthIsVatPeriodWarningForVatRelevantDocument(): void
    {
        $r = AttachmentComparator::compare(
            $this->doc(['tax_date' => '2091-04-01', 'vat_relevant' => true]),
            $this->ext(['tax_date' => '2091-03-31']),
        );

        self::assertSame('mismatch', $r['status']);
        self::assertSame('warning', $r['severity']);
        self::assertSame(['tax_date_period'], array_column($r['findings'], 'field'));
        self::assertSame('2091-04-01', $r['findings'][0]['doc']);
        self::assertSame('2091-03-31', $r['findings'][0]['attachment']);
    }

    public function testTaxDateInOtherMonthIsOnlyInfoWithoutVatImpact(): void
    {
        $r = AttachmentComparator::compare(
            $this->doc(['tax_date' => '2091-04-01', 'vat_relevant' => false]),
            $this->ext(['tax_date' => '2091-03-31']),
        );

        self::assertSame('info', $r['severity']);
        self::assertSame('tax_date_period', $r['findings'][0]['field']);
        self::assertSame('info', $r['findings'][0]['severity']);
    }

    public function testTaxDateInSameMonthIsInformative(): void
    {
        $r = AttachmentComparator::compare(
            $this->doc(['tax_date' => '2091-04-10', 'vat_relevant' => true]),
            $this->ext(['tax_date' => '2091-04-09']),
        );

        self::assertSame('info', $r['severity']);
        self::assertSame(['tax_date'], array_column($r['findings'], 'field'));
    }

    public function testMatchingDocumentHasNoFindings(): void
    {
        $r = AttachmentComparator::compare($this->doc(), $this->ext());

        self::assertSame('match', $r['status']);
        self::assertNull($r['severity']);
        self::assertSame([], $r['findings']);
    }

    public function testAmountDifferenceIsInformative(): void
    {
        $r = AttachmentComparator::compare($this->doc(['total' => 1210.0]), $this->ext(['total_with_vat' => 1250.0, 'amount_due' => null]));

        self::assertSame('info', $r['severity']);
        self::assertSame(['amount'], array_column($r['findings'], 'field'));
        self::assertEqualsWithDelta(40.0, $r['findings'][0]['diff'], 0.001);
    }

    public function testDocumentRoundingIsNoAlarm(): void
    {
        $r = AttachmentComparator::compare(
            $this->doc(['total' => 1210.40, 'rounding' => -0.40]),
            $this->ext(['total_with_vat' => 1210.0, 'amount_due' => null]),
        );

        self::assertSame('match', $r['status']);
    }

    public function testRoundingToWholeCrownsOnAttachmentIsNoAlarm(): void
    {
        // Doklad nese přesný součet, příloha „K úhradě" zaokrouhlené na koruny.
        $r = AttachmentComparator::compare(
            $this->doc(['total' => 1000.40]),
            $this->ext(['total_with_vat' => 1000.0, 'amount_due' => null]),
        );

        self::assertSame('match', $r['status']);
    }

    public function testDifferenceOverOneCrownIsReportedEvenForWholeAmounts(): void
    {
        $r = AttachmentComparator::compare(
            $this->doc(['total' => 1000.0]),
            $this->ext(['total_with_vat' => 1002.0, 'amount_due' => null]),
        );

        self::assertSame(['amount'], array_column($r['findings'], 'field'));
    }

    public function testCreditNoteSignDoesNotMatter(): void
    {
        $r = AttachmentComparator::compare(
            $this->doc(['kind' => 'credit_note', 'total' => -500.0]),
            $this->ext(['document_kind' => 'credit_note', 'total_with_vat' => 500.0, 'amount_due' => null]),
        );

        self::assertSame('match', $r['status']);
    }

    public function testAdvanceDocumentAgainstFinalInvoiceScanIsNoAlarm(): void
    {
        // Zálohová faktura s připojeným skenem konečné faktury: jiná částka i DUZP
        // jsou v pořádku — jde o jiný článek řetězu záloha → vyúčtování.
        $r = AttachmentComparator::compare(
            $this->doc(['kind' => 'advance', 'total' => 5000.0, 'tax_date' => null]),
            $this->ext(['document_kind' => 'invoice', 'total_with_vat' => 12000.0, 'amount_due' => 7000.0, 'tax_date' => '2091-05-20']),
        );

        self::assertSame([], $r['findings']);
    }

    public function testAdvanceDocumentNeverComparesTaxDate(): void
    {
        $r = AttachmentComparator::compare(
            $this->doc(['kind' => 'advance', 'tax_date' => '2091-04-01', 'vat_relevant' => true]),
            $this->ext(['document_kind' => 'advance', 'tax_date' => '2091-03-15']),
        );

        self::assertSame([], $r['findings']);
    }

    public function testFinalInvoiceAfterAdvanceMatchesAmountToPay(): void
    {
        $doc = $this->doc(['total' => 12000.0, 'amount_to_pay' => 7000.0, 'advance_paid' => 5000.0]);

        self::assertSame('match', AttachmentComparator::compare($doc, $this->ext(['total_with_vat' => null, 'amount_due' => 7000.0]))['status']);
        self::assertSame('match', AttachmentComparator::compare($doc, $this->ext(['total_with_vat' => 12000.0, 'amount_due' => 0.0]))['status']);
    }

    public function testZeroAmountsDoNotMaskDifferentTotals(): void
    {
        $r = AttachmentComparator::compare(
            $this->doc(['total' => 12000.0, 'amount_to_pay' => 0.0, 'advance_paid' => 12000.0]),
            $this->ext(['total_with_vat' => 13000.0, 'amount_due' => 0.0]),
        );

        self::assertSame(['amount'], array_column($r['findings'], 'field'));
    }

    public function testForeignCurrencyAttachmentSkipsAmount(): void
    {
        $r = AttachmentComparator::compare(
            $this->doc(['total' => 2500.0, 'currency' => 'CZK']),
            $this->ext(['total_with_vat' => 100.0, 'amount_due' => null, 'currency' => 'EUR']),
        );

        self::assertSame([], $r['findings']);
    }

    public function testNullExtractionFieldsAreNotChecked(): void
    {
        $r = AttachmentComparator::compare(
            $this->doc(),
            $this->ext(['tax_date' => null, 'total_with_vat' => null, 'amount_due' => null, 'vendor_ico' => null, 'variable_symbol' => null]),
        );

        self::assertSame('skipped', $r['status']);
        self::assertSame([], $r['findings']);
    }

    public function testCounterpartyIcoOfReceivedDocumentIsVendor(): void
    {
        $r = AttachmentComparator::compare($this->doc(), $this->ext(['vendor_ico' => '87654321']));

        self::assertSame(['counterparty_ico'], array_column($r['findings'], 'field'));
        self::assertSame('info', $r['severity']);
    }

    public function testCounterpartyIcoOfIssuedDocumentIsBuyer(): void
    {
        $issued = $this->doc(['direction' => 'issued', 'counterparty_ico' => '11223344']);

        self::assertSame('match', AttachmentComparator::compare($issued, $this->ext([
            'company_role' => 'vendor', 'vendor_ico' => '99990000', 'buyer_ico' => '11223344',
        ]))['status']);
        self::assertSame(['counterparty_ico'], array_column(AttachmentComparator::compare($issued, $this->ext([
            'company_role' => 'vendor', 'vendor_ico' => '99990000', 'buyer_ico' => '55667788',
        ]))['findings'], 'field'));
    }

    public function testSwappedPartiesAreReadFromTheOtherSide(): void
    {
        // Model dal vlastní firmu do pole dodavatele — protistrana je pak odběratel.
        $r = AttachmentComparator::compare(
            $this->doc(['own_ico' => '99990000']),
            $this->ext(['vendor_ico' => '99990000', 'buyer_ico' => '12345678', 'company_role' => null]),
        );

        self::assertSame('match', $r['status']);
    }

    public function testConflictingCompanyRoleSkipsCounterparty(): void
    {
        // Přijatý doklad, ale model tvrdí, že firma je na skenu dodavatel — IČO se nehodnotí.
        $r = AttachmentComparator::compare($this->doc(), $this->ext(['company_role' => 'vendor', 'vendor_ico' => '87654321']));

        self::assertNotContains('counterparty_ico', array_column($r['findings'], 'field'));
    }

    public function testIcoAndVariableSymbolIgnoreFormatting(): void
    {
        $r = AttachmentComparator::compare(
            $this->doc(['counterparty_ico' => '01234567', 'vs' => '0020910001']),
            $this->ext(['vendor_ico' => '1234567', 'variable_symbol' => '20910001']),
        );

        self::assertSame('match', $r['status']);
    }

    public function testVariableSymbolDifference(): void
    {
        $r = AttachmentComparator::compare($this->doc(['vs' => '20910001']), $this->ext(['variable_symbol' => '20910002']));

        self::assertSame(['variable_symbol'], array_column($r['findings'], 'field'));
    }

    public function testWarningsAreListedFirst(): void
    {
        $r = AttachmentComparator::compare(
            $this->doc(['total' => 1000.0, 'tax_date' => '2091-04-01', 'vat_relevant' => true]),
            $this->ext(['total_with_vat' => 1100.0, 'amount_due' => null, 'tax_date' => '2091-03-31']),
        );

        self::assertSame(['tax_date_period', 'amount'], array_column($r['findings'], 'field'));
        self::assertSame('warning', $r['severity']);
    }

    public function testFingerprintIsStableAndFollowsComparedValues(): void
    {
        $a = AttachmentComparator::compare($this->doc(['tax_date' => '2091-04-01']), $this->ext(['tax_date' => '2091-03-31']));
        $b = AttachmentComparator::compare($this->doc(['tax_date' => '2091-04-01']), $this->ext(['tax_date' => '2091-03-31']));
        $c = AttachmentComparator::compare($this->doc(['tax_date' => '2091-04-02']), $this->ext(['tax_date' => '2091-03-31']));
        $d = AttachmentComparator::compare($this->doc(['tax_date' => '2091-04-01']), $this->ext(['tax_date' => '2091-03-30']));

        self::assertSame(64, strlen($a['fingerprint']));
        self::assertSame($a['fingerprint'], $b['fingerprint']);
        self::assertNotSame($a['fingerprint'], $c['fingerprint'], 'změna dokladu = nový otisk');
        self::assertNotSame($a['fingerprint'], $d['fingerprint'], 'změna vytěžení = nový otisk');
    }

    /** @param array<string,mixed> $o */
    private function doc(array $o = []): array
    {
        return $o + [
            'direction' => 'received',
            'kind' => 'invoice',
            'total' => 1210.0,
            'rounding' => 0.0,
            'amount_to_pay' => null,
            'advance_paid' => null,
            'currency' => 'CZK',
            'tax_date' => '2091-04-10',
            'counterparty_ico' => '12345678',
            'own_ico' => '99990000',
            'vs' => '20910001',
            'vat_relevant' => true,
        ];
    }

    /** @param array<string,mixed> $o */
    private function ext(array $o = []): array
    {
        return $o + [
            'company_role' => 'buyer',
            'document_kind' => 'invoice',
            'vendor_ico' => '12345678',
            'buyer_ico' => '99990000',
            'variable_symbol' => '20910001',
            'tax_date' => '2091-04-10',
            'total_with_vat' => 1210.0,
            'amount_due' => 1210.0,
            'currency' => 'CZK',
        ];
    }
}
