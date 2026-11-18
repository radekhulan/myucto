<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Support;

use MyInvoice\Support\PaymentMethods;
use MyInvoice\Support\PohodaPaymentType;
use PHPUnit\Framework\TestCase;

final class PohodaPaymentTypeTest extends TestCase
{
    public function testEveryExportedPaymentTypeImportsBackToTheSameMethod(): void
    {
        foreach (PaymentMethods::ALL as $method) {
            $pohoda = PohodaPaymentType::toPohoda($method);
            if ($method === 'other') {
                self::assertNull($pohoda);
                continue;
            }
            self::assertNotNull($pohoda, $method);
            self::assertSame($method, PohodaPaymentType::fromPohoda($pohoda), $method);
        }
    }

    public function testPohodaTokensWithoutOwnMethodImportAsOther(): void
    {
        self::assertSame('bank_transfer', PohodaPaymentType::fromPohoda(''));
        foreach (['postal', 'advance', 'cheque', 'neznamy'] as $token) {
            self::assertSame('other', PohodaPaymentType::fromPohoda($token), $token);
        }
    }

    public function testMigrationImporterUsesSharedMapping(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/Service/Migration/Pohoda/InvoiceImporter.php');
        self::assertStringContainsString("PohodaPaymentType::fromPohoda(PohodaXml::text(\$h, 'paymentType/paymentType'))", $source);
        self::assertStringNotContainsString('PAYMENT_TYPES', $source);
    }
}
