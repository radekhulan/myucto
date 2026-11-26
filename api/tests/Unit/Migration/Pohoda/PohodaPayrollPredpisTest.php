<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\PohodaPayrollPredpis;
use PHPUnit\Framework\TestCase;

final class PohodaPayrollPredpisTest extends TestCase
{
    /**
     * Den předpisu měsíce je den hlavního (největšího) předpisu na Dal účtu: drobná oprava
     * na začátku února lednovou mzdu s datem 8. 2. do února neposune, předpis jiného účtu
     * také ne.
     */
    public function testMonthFollowsMainPredpisOfTheAccount(): void
    {
        $predpis = PohodaPayrollPredpis::fromEntries([
            ['key' => '331|commitment', 'date' => '2026-01-31', 'cents' => 2_000_000],
            ['key' => '331|commitment', 'date' => '2026-02-03', 'cents' => 10_000],
            ['key' => '331|commitment', 'date' => '2026-02-28', 'cents' => 2_000_000],
            ['key' => '342|commitment', 'date' => '2026-03-02', 'cents' => 300_000],
        ], [], '2026-01');

        self::assertSame('2026-01', $predpis->month('331|commitment', '2026-02-08'));
        self::assertSame('2026-02', $predpis->month('331|commitment', '2026-02-28'));
        self::assertSame('2026-02', $predpis->month('331|commitment', '2026-03-05'), 'Březen předpis na 331 nemá.');
        self::assertSame('2026-02', $predpis->month('336|commitment', '2026-03-05'), 'Předpis 342 v březnu se účtu 336 netýká.');
        self::assertSame(2_010_000, $predpis->remaining('331|commitment', '2026-03-08'));
        self::assertNull($predpis->remaining('331|commitment', '2026-01-10'), 'Prosinec je před začátkem převzatého deníku.');
        self::assertNull($predpis->remaining('336|commitment', '2026-02-08'), 'Účet bez předpisu se nehlídá.');
    }

    /** Měsíc bez předpisu na účtu převezme den zaúčtování mezd načtených z PAMICY. */
    public function testFallbackDateOfPamicaMonth(): void
    {
        $predpis = PohodaPayrollPredpis::fromEntries([['key' => '331|commitment', 'date' => '2026-01-31', 'cents' => 100]], ['2026-02' => '2026-02-28'], '2026-01');
        self::assertSame('2026-02', $predpis->month('331|commitment', '2026-02-28'));
        self::assertSame('2026-01', $predpis->month('331|commitment', '2026-02-27'));
    }
}
