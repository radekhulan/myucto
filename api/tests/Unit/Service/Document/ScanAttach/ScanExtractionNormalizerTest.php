<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Document\ScanAttach;

use MyInvoice\Service\Document\ScanAttach\ScanExtractionNormalizer;
use PHPUnit\Framework\TestCase;

final class ScanExtractionNormalizerTest extends TestCase
{
    public function testNormalizesPartiesCodesAndDates(): void
    {
        $n = ScanExtractionNormalizer::normalize([
            'vendor' => ['company_name' => '  Syntetický   dodavatel s.r.o. ', 'ic' => '876 54 321', 'dic' => 'cz 87654321'],
            'customer' => ['company_name' => 'Vlastní firma s.r.o.', 'ic' => 'CZ12345678', 'dic' => null],
            'vendor_invoice_number' => 'FV-2026/0042',
            'varsymbol' => null,
            'payment' => ['variable_symbol' => '20260042'],
            'issue_date' => '2026-03-10',
            'tax_date' => '2026-02-30',
            'total_with_vat' => '1210.004',
            'total_with_vat_rounded' => 1210,
            'currency' => 'czk',
            'barcode' => ' 4400-123456 ',
            'license_plate' => '1ab 23-45',
            'card_last4' => '4111 1111 1111 4242',
            'company_role' => 'buyer',
            'document_kind' => 'receipt',
        ]);

        self::assertSame('Syntetický dodavatel s.r.o.', $n['vendor_name']);
        self::assertSame('87654321', $n['vendor_ico']);
        self::assertSame('CZ87654321', $n['vendor_dic']);
        self::assertSame('12345678', $n['buyer_ico']);
        self::assertSame('20260042', $n['variable_symbol'], 'VS z platebních údajů, když chybí varsymbol');
        self::assertSame('2026-03-10', $n['issue_date']);
        self::assertNull($n['tax_date'], 'neexistující datum se neuloží');
        self::assertSame(1210.0, $n['total_with_vat']);
        self::assertSame(1210.0, $n['amount_due']);
        self::assertSame('CZK', $n['currency']);
        self::assertSame('4400123456', $n['barcode']);
        self::assertSame('1AB2345', $n['license_plate']);
        self::assertSame('4242', $n['card_last4'], 'z karty se ukládá jen koncovka');
        self::assertSame('buyer', $n['company_role']);
    }

    public function testUnknownRoleAndGarbageBecomeNull(): void
    {
        $n = ScanExtractionNormalizer::normalize([
            'company_role' => 'owner',
            'card_last4' => '12',
            'currency' => 'Kč',
            'vendor' => 'nejde o objekt',
        ]);

        self::assertNull($n['company_role']);
        self::assertNull($n['card_last4']);
        self::assertNull($n['currency']);
        self::assertNull($n['vendor_ico']);
    }

    public function testFulltextContainsSearchableFields(): void
    {
        $text = ScanExtractionNormalizer::fulltext([
            'vendor_name' => 'Syntetický dodavatel s.r.o.', 'vendor_ico' => '87654321',
            'document_number' => 'FV-2026/0042', 'total_with_vat' => 1210.0, 'currency' => 'CZK',
        ]);

        self::assertStringStartsWith('[vytěženo ze skenu]', $text);
        self::assertStringContainsString('Dodavatel: Syntetický dodavatel s.r.o. IČO 87654321', $text);
        self::assertStringContainsString('Číslo dokladu: FV-2026/0042', $text);
        self::assertStringContainsString('Celkem: 1210 CZK', $text);
        self::assertStringNotContainsString('Odběratel', $text);
    }
}
