<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Tests\Support\EnforcementRunFixtureTrait;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Doběh exekuce s paušální náhradou plátce mzdy a běžné výživné vedené
 * jednou částkou (dříve otevřené nálezy S4 a S3 scénářů HPP).
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

    /**
     * Běžné výživné 2 000 Kč měsíčně je opětující se dávka (§ 280 odst. 3,
     * § 290 odst. 2 o. s. ř.), evidence ale vede jednu umořovanou částku.
     * Zdokumentované chování, dokud evidence opětující se nárok neumí:
     * zapsaná lednová dávka se srazí (2 000 Kč z druhé třetiny a paušál
     * 50 Kč z volné první třetiny), v únoru se nesrazí nic a běh se zastaví
     * varováním, které říká, co udělat.
     */
    public function testCurrentMaintenanceNeedsItsNextInstalmentAndTheRunSaysSo(): void
    {
        $this->bootEnforcementRun();
        $caseId = $this->maintenanceCase();
        $this->seedRunClaim($caseId, 'current_maintenance', 200_000, '2026-01-12', 200_000);

        $january = $this->month('2026-01-01', '2026-02-10');
        self::assertSame(200_000, $january['enforcement']['allocations'][0]['total_minor_units']);
        self::assertSame(205_000, $january['enforcement']['total_withheld_minor_units']);
        self::assertNotContains('enforcement_current_maintenance_exhausted', $january['validation_codes']);
        $this->approveEnforcementRun($january);

        $february = $this->month('2026-02-01', '2026-03-10');
        self::assertSame(0, $february['enforcement']['total_withheld_minor_units']);
        $warnings = array_values(array_filter(
            $february['validations'],
            static fn (array $row): bool => $row['code'] === 'enforcement_current_maintenance_exhausted',
        ));
        self::assertCount(1, $warnings, 'Běh o nesraženém běžném výživném mlčí.');
        self::assertTrue((bool) $warnings[0]['requires_override']);
        self::assertSame("/payroll/enforcement?person={$this->employeeId}", $warnings[0]['remediation_path']);
        foreach ([
            'v tomto běhu se na ně nesrazí nic',
            'založte na další měsíční dávku nový případ s pohledávkou „běžné výživné“ a aktivujte ho',
            'do aktivního případu pohledávku přidat ani zvýšit nejde',
            'Částku za víc měsíců naráz nezapisujte',
            'Vyčerpaný případ po úhradě označte za uhrazený',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $warnings[0]['message']);
        }
        self::assertLessThanOrEqual(500, mb_strlen($warnings[0]['message']));
    }

    /**
     * Cesta, kterou varování radí: na únorovou dávku vznikne nový aktivní
     * případ a lednový, už uhrazený, se označí za uhrazený. V únoru se výživné
     * srazí (2 000 Kč a paušál 50 Kč) a varování nevznikne.
     */
    public function testNextInstalmentInANewCaseIsWithheldWithoutWarning(): void
    {
        $this->bootEnforcementRun();
        $januaryCase = $this->maintenanceCase();
        $this->seedRunClaim($januaryCase, 'current_maintenance', 200_000, '2026-01-12', 200_000);
        $january = $this->month('2026-01-01', '2026-02-10');
        $this->approveEnforcementRun($january);
        // „Označit za uhrazené“ u lednového případu a nový případ na únor.
        $this->db->pdo()->prepare(
            'UPDATE payroll_enforcement_cases SET status = "paid" WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $januaryCase]);
        $this->seedRunClaim($this->maintenanceCase(), 'current_maintenance', 200_000, '2026-01-12', 200_000);

        $february = $this->month('2026-02-01', '2026-03-10');

        self::assertSame(205_000, $february['enforcement']['total_withheld_minor_units']);
        self::assertSame(200_000, array_sum(array_column($february['enforcement']['allocations'], 'total_minor_units')));
        self::assertNotContains('enforcement_current_maintenance_exhausted', $february['validation_codes']);
    }

    /**
     * Proč varování radí nezapisovat víc měsíců naráz: evidence částku
     * neumí rozložit do měsíců, tři dávky v jedné pohledávce (6 000 Kč) se
     * srazí hned, kolik kapacita dovolí: celá druhá třetina 3 862 Kč
     * a z první třetiny zbylých 2 138 Kč, s paušálem 50 Kč celkem 6 050 Kč.
     */
    public function testSeveralInstalmentsInOneClaimAreWithheldAtOnce(): void
    {
        $this->bootEnforcementRun();
        $caseId = $this->maintenanceCase();
        $this->seedRunClaim($caseId, 'current_maintenance', 600_000, '2026-01-12', 200_000);

        $january = $this->month('2026-01-01', '2026-02-10');

        self::assertSame(600_000, $january['enforcement']['allocations'][0]['total_minor_units']);
        self::assertSame(605_000, $january['enforcement']['total_withheld_minor_units']);
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

    /** Případ výživného s oprávněným; pohledávky se k němu přidávají zvlášť. */
    private function maintenanceCase(): int
    {
        $caseId = $this->seedRunCase('remit');
        $this->seedRunParty($caseId, 'beneficiary', 'Syntetický oprávněný výživné', null);

        return $caseId;
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
