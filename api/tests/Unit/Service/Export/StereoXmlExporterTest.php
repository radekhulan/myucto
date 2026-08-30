<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Export\StereoVatTypeResolver;
use MyInvoice\Service\Export\StereoXmlExporter;
use PHPUnit\Framework\TestCase;

final class StereoXmlExporterTest extends TestCase
{
    private StereoXmlExporter $exporter;

    protected function setUp(): void
    {
        /** @var InvoiceRepository $repo */
        $repo = (new \ReflectionClass(InvoiceRepository::class))->newInstanceWithoutConstructor();
        /** @var Connection $db */
        $db = (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
        // Auto-klasifikace legacy řádku bez kódu sahá pro základní sazbu do číselníku
        // daňových konstant — testovací Connection nemá PDO, takže resolver dostane stub.
        $tax = $this->createStub(TaxConstantsRepository::class);
        $tax->method('vatRateStandard')->willReturn(21.0);
        $this->exporter = new StereoXmlExporter($repo, $db, null, new StereoVatTypeResolver($tax));
    }

    public function testLineNetUsesTaxableBaseWithoutVat(): void
    {
        $xml = $this->exporter->buildXml([$this->invoice([
            'items' => [
                $this->item([
                    'description' => 'Programatorske prace A',
                    'quantity' => 7.0,
                    'unit_price_without_vat' => 1000.0,
                    'total_without_vat' => 7000.0,
                    'total_vat' => 1470.0,
                    'total_with_vat' => 8470.0,
                ]),
                $this->item([
                    'description' => 'Programatorske prace B',
                    'quantity' => 4.0,
                    'unit_price_without_vat' => 1000.0,
                    'total_without_vat' => 4000.0,
                    'total_vat' => 840.0,
                    'total_with_vat' => 4840.0,
                ]),
            ],
            'total_without_vat' => 11000.0,
            'total_vat' => 2310.0,
            'total_with_vat' => 13310.0,
            'amount_to_pay' => 13310.0,
            'totals' => ['rounding' => 0.0],
        ])]);

        self::assertSame('7000.00', $this->xpathOne($xml, '//Rows/Row[1]/LineNet'));
        self::assertSame('1470.00', $this->xpathOne($xml, '//Rows/Row[1]/LineVAT'));
        self::assertSame('4000.00', $this->xpathOne($xml, '//Rows/Row[2]/LineNet'));
        self::assertSame('840.00', $this->xpathOne($xml, '//Rows/Row[2]/LineVAT'));

        self::assertSame('11000.00', $this->xpathOne($xml, '//DocumentTotals/TaxableTotal'));
        self::assertSame('2310.00', $this->xpathOne($xml, '//DocumentTotals/VatTotal'));
        self::assertSame('13310.00', $this->xpathOne($xml, '//DocumentTotals/NetTotal'));
        self::assertSame('13310.00', $this->xpathOne($xml, '//DocumentTotals/NetPaymentTotalRounded'));
        self::assertSame('11000.00', $this->xpathOne($xml, '//VatInfo/VATTableRow/TotalTaxableAtRate'));
        self::assertSame('2310.00', $this->xpathOne($xml, '//VatInfo/VATTableRow/VATAtRate'));
        self::assertSame('13310.00', $this->xpathOne($xml, '//VatInfo/VATTableRow/TotalWithVAT'));
        self::assertSame('Vaclavske namesti 1', $this->xpathOne($xml, '//Buyer/Address/Street'));
        self::assertSame('Praha 1', $this->xpathOne($xml, '//Buyer/Address/City'));
        self::assertSame('11000', $this->xpathOne($xml, '//Buyer/Address/PostalCode'));
        self::assertSame('Kč', $this->xpathOne($xml, '//Payment/CurrencyCode'));
        self::assertSame('Kč', $this->xpathOne($xml, '//Rows/Row[1]/CurrencyCode'));
        self::assertSame('U', $this->xpathOne($xml, '//VatInfo/TypeOfVAT'));
        self::assertSame('U', $this->xpathOne($xml, '//Rows/Row[1]/TypeOfVAT'));
        self::assertSame('false', $this->xpathOne($xml, '//Rows/Row[1]/ProcessVAT'));
        self::assertSame('false', $this->xpathOne($xml, '//Rows/Row[1]/ReverseCharge'));
        self::assertSame('true', $this->xpathOne($xml, '//DocumentTotals/ProcessVAT'));
        self::assertSame('false', $this->xpathOne($xml, '//DocumentTotals/ReverseCharge'));
    }

    public function testNonCzkCurrencyCodeUsesIsoAndMissingConstantSymbolIsEmpty(): void
    {
        $xml = $this->exporter->buildXml([$this->invoice(['currency' => 'EUR'])]);

        self::assertSame('EUR', $this->xpathOne($xml, '//Payment/CurrencyCode'));
        self::assertSame('EUR', $this->xpathOne($xml, '//Rows/Row/CurrencyCode'));
        self::assertSame('', $this->xpathOne($xml, '//Payment/ConstantSymbol'));
        self::assertSame('Jan Novak', $this->xpathOne($xml, '//Issue/IssuePerson'));
        self::assertSame('myucto.cz', $this->xpathOne($xml, '//HEADER/Source/SoftwareVendor'));
        self::assertSame('myucto.cz', $this->xpathOne($xml, '//HEADER/Source/SoftwareProduct'));
        self::assertNull($this->xpathOne($xml, '//DocumentTotals/TypeOfOperation'));
        self::assertNull($this->xpathOne($xml, '//Rows/Row/TypeOfOperation'));
    }

    public function testDomesticReverseChargeMapsToStereoUrp(): void
    {
        $xml = $this->exporter->buildXml([$this->invoice([
            'reverse_charge' => true,
            'items' => [
                $this->item([
                    'vat_classification_code' => '25s',
                    'vat_rate_snapshot' => 21.0,
                    'total_without_vat' => 5000.0,
                    'total_vat' => 0.0,
                    'total_with_vat' => 5000.0,
                ]),
            ],
            'amount_to_pay' => 5000.0,
        ])]);

        self::assertSame('URP', $this->xpathOne($xml, '//VatInfo/TypeOfVAT'));
        self::assertNull($this->xpathOne($xml, '//DocumentTotals/TypeOfOperation'));
        self::assertNull($this->xpathOne($xml, '//Rows/Row/TypeOfOperation'));
        self::assertSame('URP', $this->xpathOne($xml, '//Rows/Row/TypeOfVAT'));
        self::assertSame('false', $this->xpathOne($xml, '//Rows/Row/ProcessVAT'));
        self::assertSame('true', $this->xpathOne($xml, '//Rows/Row/ReverseCharge'));
        self::assertSame('true', $this->xpathOne($xml, '//DocumentTotals/ProcessVAT'));
        self::assertSame('true', $this->xpathOne($xml, '//DocumentTotals/ReverseCharge'));
    }

    public function testInvoiceVatClassificationMakesStereoVatTypeConsistentAcrossWholeDocument(): void
    {
        $xml = $this->exporter->buildXml([$this->invoice([
            'vat_classification_code' => '22',
            'items' => [
                $this->item([
                    'description' => 'Tuzemska sluzba',
                    'vat_classification_code' => '1',
                    'total_without_vat' => 1000.0,
                    'total_vat' => 210.0,
                    'total_with_vat' => 1210.0,
                ]),
                $this->item([
                    'description' => 'Sluzba do EU',
                    'vat_classification_code' => '22',
                    'vat_rate_snapshot' => 0.0,
                    'total_without_vat' => 2000.0,
                    'total_vat' => 0.0,
                    'total_with_vat' => 2000.0,
                ]),
            ],
            'amount_to_pay' => 3210.0,
        ])]);

        self::assertSame('UVSP', $this->xpathOne($xml, '//VatInfo/TypeOfVAT'));
        self::assertSame('UVSP', $this->xpathOne($xml, '//Rows/Row[1]/TypeOfVAT'));
        self::assertSame('UVSP', $this->xpathOne($xml, '//Rows/Row[2]/TypeOfVAT'));
        self::assertSame('true', $this->xpathOne($xml, '//DocumentTotals/ProcessVAT'));
        self::assertSame('false', $this->xpathOne($xml, '//DocumentTotals/ReverseCharge'));
    }

    public function testMixedStereoVatTypesWithoutInvoiceClassificationFailExport(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stereo XML vyžaduje jeden Typ DPH pro celý doklad #2026001.');

        $this->exporter->buildXml([$this->invoice([
            'items' => [
                $this->item(['vat_classification_code' => '1']),
                $this->item([
                    'vat_classification_code' => '22',
                    'vat_rate_snapshot' => 0.0,
                    'total_without_vat' => 2000.0,
                    'total_vat' => 0.0,
                    'total_with_vat' => 2000.0,
                ]),
            ],
        ])]);
    }

    private function xpathOne(string $xml, string $expr): ?string
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'Stereo XML neni well-formed.');
        $xp = new \DOMXPath($dom);
        $node = $xp->query($expr)->item(0);

        return $node?->textContent;
    }

    public function testOssRowIsRejectedInsteadOfExportedAsDomesticExempt(): void
    {
        // Bez guardu resolver propadne u DE 19 % na klasifikaci '3' → TypeOfVAT "UO"
        // (tuzemské osvobozené plnění), takže by se OSS plnění naimportovalo do účetnictví
        // se špatným zařazením.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/OSS/');

        $this->exporter->buildXml([$this->invoice([
            'client_snapshot' => ['company_name' => 'Kunde GmbH', 'country_iso2' => 'DE'],
            'items' => [$this->item([
                'vat_rate_snapshot' => 19.0,
                'oss_applicable' => 1,
                'oss_consumer_country' => 'DE',
            ])],
        ])]);
    }

    public function testNonOssInvoiceStillExports(): void
    {
        $xml = $this->exporter->buildXml([$this->invoice()]);

        self::assertStringContainsString('<TypeOfVAT>U</TypeOfVAT>', $xml);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function invoice(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'invoice_type' => 'invoice',
            'varsymbol' => '2026001',
            'issue_date' => '2026-05-04',
            'tax_date' => '2026-05-04',
            'due_date' => '2026-05-18',
            'currency' => 'CZK',
            'payment_method' => 'bank_transfer',
            'created_by' => 7,
            'created_by_name' => 'Jan Novak',
            'advance_paid_amount' => 0.0,
            'amount_to_pay' => 3049.2,
            'rounding' => 0.0,
            'client_snapshot' => [
                'company_name' => 'Odberatel a.s.',
                'street' => 'Vaclavske namesti 1',
                'city' => 'Praha 1',
                'zip' => '11000',
                'country_iso2' => 'CZ',
                'ic' => '27140130',
                'dic' => 'CZ27140130',
            ],
            'supplier_snapshot' => [
                'company_name' => 'Dodavatel s.r.o.',
                'street' => 'Zkusebni 123/4',
                'city' => 'Vzorov',
                'zip' => '10000',
                'country_iso2' => 'CZ',
                'ic' => '01698401',
                'dic' => 'CZ01698401',
                'is_vat_payer' => true,
            ],
            'bank_snapshot' => [
                'account_number' => '1000000005',
                'bank_code' => '0100',
                'bank_name' => 'Komercni banka',
            ],
            'items' => [$this->item()],
            'totals' => ['rounding' => 0.0],
        ], $overrides);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function item(array $overrides = []): array
    {
        return array_merge([
            'description' => 'Vyvoj systemu',
            'quantity' => 1.0,
            'unit' => 'ks',
            'unit_price_without_vat' => 2520.0,
            'vat_rate_snapshot' => 21.0,
            'total_without_vat' => 2520.0,
            'total_vat' => 529.2,
            'total_with_vat' => 3049.2,
        ], $overrides);
    }
}
