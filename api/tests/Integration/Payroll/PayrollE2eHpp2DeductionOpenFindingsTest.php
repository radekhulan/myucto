<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Tests\Support\EnforcementRunFixtureTrait;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * OTEVŘENÉ NÁLEZY srážek ze mzdy: testy popisují chování podle předpisu
 * a nad současným kódem PADAJÍ. Oprava vyžaduje rozhodnutí (datový model
 * běžného výživného, výklad paušální náhrady na doběhu exekuce), proto
 * nejsou součástí {@see PayrollE2eHpp2DeductionMonthsTest}.
 *
 * HPP 35 000 Kč bez prohlášení, čistá mzda 25 690 Kč, nezabavitelná částka
 * 14 102 Kč, třetina 3 862 Kč.
 */
#[Group('integration')]
final class PayrollE2eHpp2DeductionOpenFindingsTest extends TestCase
{
    use EnforcementRunFixtureTrait;
    use IsolatedSupplierTrait;

    protected function tearDown(): void
    {
        $this->tearDownEnforcementRun();
    }

    /**
     * Běžné výživné 2 000 Kč měsíčně se sráží KAŽDÝ měsíc, dokud soud výkon
     * rozhodnutí nezastaví (§ 280 odst. 3, § 290 odst. 2 o. s. ř.). Evidence
     * ale vede jednu „původní výši pohledávky“ a po jejím vyčerpání se
     * v dalším měsíci nesrazí nic (běh na to jen upozorní).
     */
    public function testCurrentMaintenanceIsWithheldEveryMonth(): void
    {
        $this->bootEnforcementRun();
        $this->claimIn('current_maintenance', '2026-01-12', 200_000, 200_000);

        $january = $this->month('2026-01-01', '2026-02-10');
        self::assertSame(200_000, $january['enforcement']['total_withheld_minor_units']);
        $this->approveEnforcementRun($january);

        $february = $this->month('2026-02-01', '2026-03-10');
        self::assertSame(200_000, $february['enforcement']['total_withheld_minor_units'], 'Únorové běžné výživné se nesrazilo.');
    }

    /**
     * Doběh exekuce: zbývá 1 000 Kč, třetina 3 862 Kč stačí na dluh i na
     * paušál plátce mzdy. Paušál jde k tíži povinného (§ 270 odst. 2
     * o. s. ř.) a uspokojuje se z první třetiny před ostatními (§ 279
     * odst. 1), takže se v lednu srazí 1 050 Kč, oprávněný dostane celých
     * 1 000 Kč a v únoru se už nesráží nic.
     *
     * Současný kód srazí 1 000 Kč, oprávněnému pošle 950 Kč a zbylých 50 Kč
     * umořuje dalších pět měsíců (33, 11, 4, 1 Kč), pak sráží 1 Kč měsíčně
     * jen na paušál a pohledávka neskončí nikdy.
     */
    public function testFinishingEnforcementPaysCreditorInFullAndEnds(): void
    {
        $this->bootEnforcementRun();
        $this->claimIn('non_priority', '2026-01-12', 100_000);

        $january = $this->month('2026-01-01', '2026-02-10');
        self::assertSame(100_000, $january['enforcement']['allocations'][0]['total_minor_units'], 'Oprávněný nedostal celý zbytek pohledávky.');
        self::assertSame(105_000, $january['enforcement']['total_withheld_minor_units']);
        $this->approveEnforcementRun($january);

        $february = $this->month('2026-02-01', '2026-03-10');
        self::assertSame(0, $february['enforcement']['total_withheld_minor_units'], 'Doplacená exekuce se dál sráží.');
    }

    /** Mzdový vstup, měsíční evidence a výpočet běhu za dané období. */
    private function month(string $periodStart, string $paymentDate, int $grossMinor = 3_500_000): array
    {
        $this->wage($periodStart, $grossMinor);
        $this->seedRunMonthEvidence($periodStart);

        return $this->calculateEnforcementRun($periodStart, $paymentDate);
    }

    private function wage(string $periodStart, int $grossMinor = 3_500_000): void
    {
        $this->seedRunInput($this->componentIds['MZDA_MESICNI'], $grossMinor, $periodStart);
    }

    /** Vytvoří pohledávku v novém řízení a vrátí její klíč (claim_id výsledku). */
    private function claimIn(
        string $category,
        string $deliveredOn,
        int $outstandingMinor = 10_000_000,
        ?int $maintenanceMinor = null,
    ): string {
        $caseId = $this->seedRunCase('remit');
        $this->seedRunParty($caseId, 'beneficiary', 'Syntetický oprávněný ' . $category, null);
        $claimId = $this->seedRunClaim($caseId, $category, $outstandingMinor, $deliveredOn, $maintenanceMinor);
        $statement = $this->db->pdo()->prepare('SELECT claim_key FROM payroll_enforcement_claims WHERE supplier_id = ? AND id = ?');
        $statement->execute([$this->supplierId, $claimId]);

        return (string) $statement->fetchColumn();
    }
}
