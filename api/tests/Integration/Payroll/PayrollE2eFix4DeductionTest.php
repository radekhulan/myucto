<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Tests\Support\EnforcementRunFixtureTrait;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Doběh exekuce s paušální náhradou plátce mzdy (dříve otevřený nález S4
 * scénářů HPP).
 *
 * HPP 35 000 Kč bez prohlášení: SP 2 485, ZP 1 575, záloha 5 250, čistá mzda
 * 25 690 Kč; nezabavitelná částka 14 101,50 → 14 102 Kč; zbytek 11 588 →
 * 11 586 Kč dělitelných třemi, třetina 3 862 Kč.
 */
#[Group('integration')]
final class PayrollE2eFix4DeductionTest extends TestCase
{
    use EnforcementRunFixtureTrait;
    use IsolatedSupplierTrait;

    protected function tearDown(): void
    {
        $this->tearDownEnforcementRun();
    }

    /**
     * Doběh exekuce: zbývá 1 000 Kč, třetina 3 862 Kč stačí na dluh i na
     * paušál plátce mzdy. Paušál hradí povinný (§ 270 odst. 2 o. s. ř.:
     * „Plátce mzdy má vůči povinnému nárok…“; § 87 odst. 3 exekučního řádu:
     * „Paušálně stanovenou náhradu nákladů hradí plátci mzdy povinný.“)
     * a uspokojuje se z první třetiny před ostatními (§ 279 odst. 1), takže
     * se v lednu srazí 1 050 Kč, oprávněný dostane celých 1 000 Kč
     * a v únoru se už nesráží nic.
     *
     * Dřív se srazilo 1 000 Kč, oprávněnému šlo 950 Kč a zbylých 50 Kč se
     * umořovalo dalších pět měsíců (33, 11, 4, 1 Kč), pak se srážela 1 Kč
     * měsíčně jen na paušál a pohledávka neskončila nikdy.
     */
    public function testFinishingEnforcementPaysCreditorInFullAndEnds(): void
    {
        $this->bootEnforcementRun();
        $this->claimIn('non_priority', '2026-01-12', 100_000);

        $january = $this->month('2026-01-01', '2026-02-10');
        self::assertSame(100_000, $january['enforcement']['allocations'][0]['total_minor_units'], 'Oprávněný nedostal celý zbytek pohledávky.');
        self::assertSame(5_000, $january['enforcement']['employer_flat_fee_minor_units']);
        self::assertSame(105_000, $january['enforcement']['total_withheld_minor_units']);
        $this->approveEnforcementRun($january);

        $february = $this->month('2026-02-01', '2026-03-10');
        self::assertSame(0, $february['enforcement']['total_withheld_minor_units'], 'Doplacená exekuce se dál sráží.');
        self::assertSame(0, $february['enforcement']['employer_flat_fee_minor_units']);
    }

    /**
     * Celý průběh pohledávky 5 000 Kč: leden a únor jsou omezené třetinou
     * (sraženo 3 862, oprávněnému 3 812; pak 1 188 + 50 = 1 238), v březnu je
     * doplaceno. Oprávněný dostane přesně 5 000 Kč, povinný zaplatí navíc
     * dvakrát paušál 50 Kč a po doplacení se nesráží ani koruna.
     */
    public function testClaimIsPaidOffExactlyAndNothingIsWithheldAfterwards(): void
    {
        $this->bootEnforcementRun();
        $this->claimIn('non_priority', '2026-01-12', 500_000);

        $paid = [];
        $withheld = [];
        foreach ([['2026-01-01', '2026-02-10'], ['2026-02-01', '2026-03-10'], ['2026-03-01', '2026-04-10'], ['2026-04-01', '2026-05-11']] as [$period, $payday]) {
            $run = $this->month($period, $payday);
            $withheld[] = $run['enforcement']['total_withheld_minor_units'];
            $paid[] = $run['enforcement']['allocations'][0]['total_minor_units'] ?? 0;
            $this->approveEnforcementRun($run);
        }

        self::assertSame([386_200, 123_800, 0, 0], $withheld);
        self::assertSame([381_200, 118_800, 0, 0], $paid);
        self::assertSame(500_000, array_sum($paid));
    }

    /**
     * Zbytek po starém výpočtu (33 Kč, které dřív dobíhaly po korunách) se
     * doplatí naráz: strop paušálu je třetina sražené částky na koruny
     * nahoru, tedy 17 Kč ze srážky 50 Kč, a oprávněný dostane celých 33 Kč.
     */
    public function testSmallRemainderFromTheOldCalculationEndsInOneMonth(): void
    {
        $this->bootEnforcementRun();
        $this->claimIn('non_priority', '2026-01-12', 3_300);

        $january = $this->month('2026-01-01', '2026-02-10');
        self::assertSame(3_300, $january['enforcement']['allocations'][0]['total_minor_units']);
        self::assertSame(1_700, $january['enforcement']['employer_flat_fee_minor_units']);
        self::assertSame(5_000, $january['enforcement']['total_withheld_minor_units']);
        $this->approveEnforcementRun($january);

        $february = $this->month('2026-02-01', '2026-03-10');
        self::assertSame(0, $february['enforcement']['total_withheld_minor_units']);
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
