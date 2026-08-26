<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\CsszCertificateSerialNumber;
use PHPUnit\Framework\TestCase;

final class CsszCertificateSerialNumberTest extends TestCase
{
    public function testRegisteredNotationKeepsMeaningfulLeadingZeroForDisplayAndAudit(): void
    {
        self::assertSame(
            '0176ac6f',
            CsszCertificateSerialNumber::normalizeRegisteredInput('01:76 AC-6F'),
        );
    }

    public function testLeadingZeroHexMatchesCertificateWithoutThatFormattingZero(): void
    {
        self::assertTrue(CsszCertificateSerialNumber::matches('176ac6f', '0176AC6F'));
        self::assertSame(
            '0176ac6f',
            CsszCertificateSerialNumber::formatRegisteredForDisplay('176ac6f'),
        );
    }

    public function testDecimalNotationMatchesCertificateHex(): void
    {
        self::assertTrue(CsszCertificateSerialNumber::matches('176b96f', '24557935'));
    }

    public function testDigitOnlyHexCertificateIsNotMistakenForDecimal(): void
    {
        self::assertTrue(CsszCertificateSerialNumber::matches('001234', '1234'));
        self::assertTrue(CsszCertificateSerialNumber::matches('001234', '4660'));
        self::assertFalse(CsszCertificateSerialNumber::matches('001234', '4661'));
    }

    public function testUnreadableValueIsRejected(): void
    {
        self::assertNull(CsszCertificateSerialNumber::normalizeRegisteredInput('viz příloha'));
        self::assertFalse(CsszCertificateSerialNumber::matches('176ac6f', 'viz příloha'));
    }
}
