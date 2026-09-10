<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\InvoiceImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Daňová povaha faktury z Money: co se převezme jako běžný tuzemský doklad a co jako
 * koncept k ruční kontrole. Členění DPH (`KodDPH`) nese řádky přiznání, na které Money
 * doklad zařadilo.
 */
final class InvoiceClassificationTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>,bool,float,bool,string}> */
    public static function cases(): iterable
    {
        yield 'tuzemská přijatá' => [['Druh' => 'N', 'KodDPH' => '19Ř40,41'], false, 210.0, false, 'full'];
        yield 'tuzemská vydaná' => [['Druh' => 'N', 'KodDPH' => '19Ř01,02'], true, 210.0, false, 'full'];
        yield 'bez členění a bez daně' => [['Druh' => 'N', 'KodDPH' => ''], false, 0.0, false, 'full'];
        yield 'přijatá mimo přiznání s daní' => [['Druh' => 'N', 'KodDPH' => '19Ř00P'], false, 504.0, false, 'none'];
        yield 'vydaná mimo přiznání s daní' => [['Druh' => 'N', 'KodDPH' => '19Ř00U'], true, 210.0, true, 'full'];
        yield 'přenesená daňová povinnost' => [['Druh' => 'N', 'KodDPH' => '19Ř10,43'], false, 0.0, true, 'full'];
        yield 'dodání do EU na vydané' => [['Druh' => 'N', 'KodDPH' => '19Ř20'], true, 0.0, true, 'full'];
        yield 'neznámý tvar členění' => [['Druh' => 'N', 'KodDPH' => 'PD'], false, 210.0, true, 'full'];
        yield 'daň bez členění' => [['Druh' => 'N', 'KodDPH' => ''], false, 210.0, true, 'full'];
        yield 'zálohová' => [['Druh' => 'Z', 'KodDPH' => '19Ř40,41'], false, 210.0, true, 'full'];
        yield 'dobropis' => [['Druh' => 'N', 'Dobropis' => 1, 'KodDPH' => '19Ř40,41'], false, -105.0, true, 'full'];
        yield 'storno' => [['Druh' => 'N', 'Storno' => 1, 'KodDPH' => '19Ř40,41'], false, 210.0, true, 'full'];
        yield 'neúčtovat' => [['Druh' => 'N', 'Neuctovat' => 1, 'KodDPH' => '19Ř40,41'], false, 210.0, true, 'full'];
        yield 'cizí měna' => [['Druh' => 'N', 'Mena' => 'EUR', 'Kurs' => 25.0, 'KodDPH' => '19Ř40,41'], false, 525.0, true, 'full'];
        yield 'koruna výslovně' => [['Druh' => 'N', 'Mena' => 'CZK', 'KodDPH' => '19Ř40,41'], false, 210.0, false, 'full'];
    }

    /** @param array<string,mixed> $row */
    #[DataProvider('cases')]
    public function testClassification(array $row, bool $issued, float $vat, bool $review, string $deduction): void
    {
        $class = InvoiceImporter::classify($row, $issued, $vat);

        self::assertSame($review, $class['reasons'] !== [], json_encode($class, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame($deduction, $class['vat_deduction']);
    }

    public function testPaymentMethodFromMoneyLabel(): void
    {
        self::assertSame('bank_transfer', InvoiceImporter::paymentMethod('převodem'));
        self::assertSame('card', InvoiceImporter::paymentMethod('platební kartou'));
        self::assertSame('cash', InvoiceImporter::paymentMethod('v hotovosti'));
        self::assertSame('cash_on_delivery', InvoiceImporter::paymentMethod('dobírkou'));
        self::assertSame('direct_debit', InvoiceImporter::paymentMethod('inkasem'));
        self::assertSame('offset', InvoiceImporter::paymentMethod('zápočtem'));
        self::assertSame('bank_transfer', InvoiceImporter::paymentMethod(''));
        self::assertSame('other', InvoiceImporter::paymentMethod('šekem'));
    }
}
