<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollSnapshotHealthInsurers;
use PHPUnit\Framework\TestCase;

/**
 * Kód zdravotní pojišťovny musí zůstat řetězcem.
 *
 * Kódy se sbíraly jako klíče pole (`$codes[$code] = true`). PHP číselný
 * řetězcový klíč tiše převede na int, takže `array_keys()` vracelo 111 místo
 * '111'. Porovnání s ověřenými účty je striktní, int se stringu nerovná —
 * a kontrola pak hlásila nedoplněný účet u KAŽDÉ pojišťovny, i u té, která
 * ověřený a účinný účet měla. Kód pojišťovny je vždy tři číslice, takže se to
 * netýkalo žádného okrajového případu, ale úplně všech.
 */
final class PayrollRunHealthInsurerCodeTest extends TestCase
{
    public function testCodesStayStringsSoStrictComparisonMatches(): void
    {
        $codes = self::codes([
            'people' => [
                self::person('111'),
                self::person('201'),
            ],
        ]);

        self::assertSame(['111', '201'], $codes);
        self::assertTrue(in_array($codes[0], ['111'], true));
    }

    public function testSameInsurerCountsOnce(): void
    {
        $codes = self::codes([
            'people' => [
                self::person('111'),
                self::person('111'),
            ],
        ]);

        self::assertSame(['111'], $codes);
    }

    public function testNonsenseCodesAreIgnored(): void
    {
        $codes = self::codes([
            'people' => [
                self::person('11'),
                self::person('1111'),
                self::person(''),
                ['statutory_evidence' => ['health' => ['coverage' => []]]],
            ],
        ]);

        self::assertSame([], $codes);
    }

    /** @return array<string,mixed> */
    private static function person(string $code): array
    {
        return [
            'statutory_evidence' => [
                'health' => ['coverage' => ['insurer_code' => $code]],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $snapshotData
     * @return list<string>
     */
    private static function codes(array $snapshotData): array
    {
        return PayrollSnapshotHealthInsurers::fromSnapshot($snapshotData);
    }
}
