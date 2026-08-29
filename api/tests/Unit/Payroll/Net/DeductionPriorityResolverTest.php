<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Net;

use MyInvoice\Service\Payroll\Net\DeductionPriorityResolver;
use MyInvoice\Service\Payroll\Net\PayrollDeductionRequest;
use PHPUnit\Framework\TestCase;

/**
 * Rozvrh kapacity mezi dohody o srážkách ze mzdy — § 2045 odst. 2 občanského
 * zákoníku ve spojení s § 148 odst. 2 zákoníku práce a § 280 odst. 5 o. s. ř.
 */
final class DeductionPriorityResolverTest extends TestCase
{
    /**
     * § 280 odst. 5 věta druhá o. s. ř.: „nestačí-li částka na ně připadající
     * k jejich plnému uspokojení, uspokojí se poměrně". Do 8/2026 se dohody se
     * stejným pořadím uspokojovaly sekvenčně podle `strcmp()` referencí, takže
     * abecedně první vzala všechno a druhá nedostala nic (nález E-08).
     */
    public function testEqualRankAgreementsShareTheCapacityProportionally(): void
    {
        $results = $this->indexed((new DeductionPriorityResolver())->resolve([
            new PayrollDeductionRequest('a-najem', 10, 100_000, null, true),
            new PayrollDeductionRequest('z-sporeni', 10, 300_000, null, true),
        ], 100_000));

        self::assertSame(25_000, $results['a-najem']);
        self::assertSame(75_000, $results['z-sporeni']);
    }

    /**
     * Zbytkové haléře dostane ten, komu při poměrném dělení zbylo nejvíc;
     * součet musí sedět na kapacitu na haléř.
     */
    public function testProportionalSplitDistributesTheRemainderDeterministically(): void
    {
        $results = $this->indexed((new DeductionPriorityResolver())->resolve([
            new PayrollDeductionRequest('a', 10, 100, null, true),
            new PayrollDeductionRequest('b', 10, 100, null, true),
            new PayrollDeductionRequest('c', 10, 100, null, true),
        ], 100));

        self::assertSame(100, array_sum($results));
        self::assertSame([34, 33, 33], [$results['a'], $results['b'], $results['c']]);
    }

    /**
     * Rozdílné pořadí se dál uspokojuje postupně — poměrně se dělí jen uvnitř
     * jednoho pořadí.
     */
    public function testDifferentRanksAreStillSatisfiedSequentially(): void
    {
        $results = $this->indexed((new DeductionPriorityResolver())->resolve([
            new PayrollDeductionRequest('zadruhe', 20, 100_000, null, true),
            new PayrollDeductionRequest('prvni', 10, 80_000, null, true),
        ], 100_000));

        self::assertSame(80_000, $results['prvni']);
        self::assertSame(20_000, $results['zadruhe']);
    }

    /**
     * Pořadí se řídí dnem doručení dohody plátci mzdy (§ 2045 odst. 2 OZ,
     * § 280 odst. 5 o. s. ř.), ne ručně nastaveným `priority`. Dřív dostala
     * přednost dohoda s nižším `priority` bez ohledu na den doručení
     * (nález E-03).
     */
    public function testEarlierDeliveryOutranksALowerPriorityNumber(): void
    {
        $results = $this->indexed((new DeductionPriorityResolver())->resolve([
            new PayrollDeductionRequest('pozdeji', 10, 100_000, null, true, '2026-03-01'),
            new PayrollDeductionRequest('driv', 99, 80_000, null, true, '2026-01-15'),
        ], 100_000));

        self::assertSame(80_000, $results['driv']);
        self::assertSame(20_000, $results['pozdeji']);
    }

    /**
     * Uvnitř jednoho dne doručení pořadí dál rozlišuje `priority` — je to
     * vědomé rozhodnutí účetní. Poměrně se dělí až při shodě obojího.
     */
    public function testSameDayDeliveryWithEqualPriorityIsSharedProportionally(): void
    {
        $results = $this->indexed((new DeductionPriorityResolver())->resolve([
            new PayrollDeductionRequest('a', 10, 100_000, null, true, '2026-01-15'),
            new PayrollDeductionRequest('b', 10, 100_000, null, true, '2026-01-15'),
            new PayrollDeductionRequest('c', 20, 100_000, null, true, '2026-01-15'),
        ], 100_000));

        self::assertSame(50_000, $results['a']);
        self::assertSame(50_000, $results['b']);
        self::assertSame(0, $results['c']);
    }

    /**
     * Chybějící den doručení nesmí nikomu pořadí vylepšit — legacy dohoda se
     * řadí až za všechny, u kterých je den doručení znám.
     */
    public function testAgreementWithoutADeliveryDateRanksLast(): void
    {
        $results = $this->indexed((new DeductionPriorityResolver())->resolve([
            new PayrollDeductionRequest('legacy', 1, 100_000, null, true),
            new PayrollDeductionRequest('doruceno', 90, 80_000, null, true, '2026-05-05'),
        ], 100_000));

        self::assertSame(80_000, $results['doruceno']);
        self::assertSame(20_000, $results['legacy']);
    }

    /**
     * Limit dohody a pozastavení se uplatní i uvnitř poměrného dělení: co na
     * pozastavenou dohodu nepřipadne, zůstane ostatním.
     */
    public function testInactiveAndCappedAgreementsDoNotConsumeTheirShare(): void
    {
        $resolved = (new DeductionPriorityResolver())->resolve([
            new PayrollDeductionRequest('pozastaveno', 10, 100_000, null, false),
            new PayrollDeductionRequest('limit', 10, 100_000, 20_000, true),
            new PayrollDeductionRequest('bezlimitu', 10, 100_000, null, true),
        ], 200_000);
        $results = $this->indexed($resolved);

        self::assertSame(0, $results['pozastaveno']);
        self::assertSame(20_000, $results['limit']);
        self::assertSame(100_000, $results['bezlimitu']);

        foreach ($resolved as $result) {
            if ($result->deductionReference === 'pozastaveno') {
                // Neaktivní dohoda se v tom měsíci neprovádí, takže z ní
                // žádný schodek vůči věřiteli nevzniká (nález E-17).
                self::assertSame(100_000, $result->unappliedMinorUnits);
                self::assertSame(0, $result->shortfallMinorUnits());
            }
        }
    }

    public function testRejectsNegativeCapacity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DeductionPriorityResolver())->resolve([], -1);
    }

    public function testRejectsAnInvalidDeliveryDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PayrollDeductionRequest('a', 10, 1, null, true, '15. 1. 2026');
    }

    /**
     * @param list<\MyInvoice\Service\Payroll\Net\PayrollDeductionResult> $results
     * @return array<string,int>
     */
    private function indexed(array $results): array
    {
        $indexed = [];
        foreach ($results as $result) {
            $indexed[$result->deductionReference] = $result->appliedMinorUnits;
        }

        return $indexed;
    }
}
