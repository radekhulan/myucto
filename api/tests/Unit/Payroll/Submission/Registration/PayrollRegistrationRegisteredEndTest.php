<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationBusinessMatrix as Matrix;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationRegisteredEnd;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use PHPUnit\Framework\TestCase;

final class PayrollRegistrationRegisteredEndTest extends TestCase
{
    /**
     * GitHub MPSV #380: 10224 je datum, které vede ČSSZ, tedy z odeslané
     * odhlášky; karta vztahu je jen záloha a jen s datem, které už nastalo.
     */
    public function testEndDateComesFromTheSentA2BeforeTheCard(): void
    {
        self::assertSame('2026-11-30', PayrollRegistrationRegisteredEnd::endOn(
            4,
            Matrix::VARIANT_OST,
            '2026-11-30',
            '2026-11-29',
            '2026-12-10',
        ));
        self::assertSame('2026-11-29', PayrollRegistrationRegisteredEnd::endOn(
            4,
            Matrix::VARIANT_OST,
            null,
            '2026-11-29',
            '2026-12-10',
        ));
        self::assertNull(PayrollRegistrationRegisteredEnd::endOn(
            4,
            Matrix::VARIANT_OST,
            null,
            '2026-12-31',
            '2026-12-10',
        ));
    }

    /** EDV 1.4.0.7 i 1.5.0.3, ID 10224: A3-10 a A3-SPEC „/", A4 všechny varianty „N". */
    public function testVariantsThatMayCarryTheEndDate(): void
    {
        self::assertNull(PayrollRegistrationRegisteredEnd::endOn(3, Matrix::VARIANT_SPEC, '2026-11-30', null, '2026-12-10'));
        self::assertSame('2026-11-30', PayrollRegistrationRegisteredEnd::endOn(4, Matrix::VARIANT_10, '2026-11-30', null, '2026-12-10'));
        self::assertTrue(PayrollRegistrationRegisteredEnd::carriesTerminationParts(4, Matrix::VARIANT_OST));
        self::assertTrue(PayrollRegistrationRegisteredEnd::carriesTerminationParts(4, Matrix::VARIANT_SPEC));
        self::assertFalse(PayrollRegistrationRegisteredEnd::carriesTerminationParts(4, Matrix::VARIANT_10));
        self::assertFalse(PayrollRegistrationRegisteredEnd::carriesTerminationParts(3, Matrix::VARIANT_OST));
    }

    /** Odpovědi ČSSZ 4, 5 a 13 k NV2027: REGZEC25 bez 10224 od 23. 12. 2026 zastarává. */
    public function testFrozenChangeWithoutEndExpiresOn23December(): void
    {
        self::assertFalse(PayrollRegistrationSchemaCatalog::changeVintageExpired(3, false, JmhzSpecVersion::V2026, '2026-12-22'));
        self::assertTrue(PayrollRegistrationSchemaCatalog::changeVintageExpired(3, false, JmhzSpecVersion::V2026, '2026-12-23'));
        self::assertTrue(PayrollRegistrationSchemaCatalog::changeVintageExpired(4, false, JmhzSpecVersion::V2026, '2027-01-05'));
        self::assertFalse(PayrollRegistrationSchemaCatalog::changeVintageExpired(4, true, JmhzSpecVersion::V2026, '2027-01-05'));
        self::assertFalse(PayrollRegistrationSchemaCatalog::changeVintageExpired(3, false, JmhzSpecVersion::V2027, '2027-01-05'));
        self::assertFalse(PayrollRegistrationSchemaCatalog::changeVintageExpired(2, false, JmhzSpecVersion::V2026, '2027-01-05'));
    }
}
