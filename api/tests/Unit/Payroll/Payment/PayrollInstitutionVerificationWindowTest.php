<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Payment;

use MyInvoice\Service\Payroll\Payment\PayrollInstitutionVerificationWindow;
use PHPUnit\Framework\TestCase;

/**
 * Mez pro datum ověření platebního účtu.
 *
 * Pravidlo „ověření nesmí být pozdější než splatnost" shazovalo každé ZPĚTNÉ
 * zpracování období: firma přebírající starší měsíce z ruční rekapitulace má
 * účty ověřené dnes a splatnosti dávno za sebou. Jediná cesta ven by byla
 * přepsat datum ověření na dřívější, tedy zfalšovat doklad.
 */
final class PayrollInstitutionVerificationWindowTest extends TestCase
{
    public function testFuturePayableKeepsTheDueDateAsTheLimit(): void
    {
        self::assertSame(
            '2026-11-20',
            PayrollInstitutionVerificationWindow::latestAcceptable('2026-11-20', '2026-09-02'),
        );
    }

    public function testPastPayableMeasuresTheVerificationAgainstToday(): void
    {
        self::assertSame(
            '2026-09-02',
            PayrollInstitutionVerificationWindow::latestAcceptable('2026-02-20', '2026-09-02'),
        );
    }

    public function testDueTodayKeepsToday(): void
    {
        self::assertSame(
            '2026-09-02',
            PayrollInstitutionVerificationWindow::latestAcceptable('2026-09-02', '2026-09-02'),
        );
    }

    /**
     * Ověření datované DO BUDOUCNA neprojde ani zpětně — mez se posouvá jen
     * k dnešku, ne dál.
     */
    public function testNeverReachesBeyondToday(): void
    {
        $limit = PayrollInstitutionVerificationWindow::latestAcceptable('2020-01-31', '2026-09-02');
        self::assertSame('2026-09-02', $limit);
        self::assertTrue('2026-09-03' > $limit);
    }
}
