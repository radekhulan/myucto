<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Tests\Support\EnforcementRunFixtureTrait;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Srážky ze mzdy přes víc schválených měsíců (leden až duben 2026):
 * umořování pohledávky, doběh exekuce a běžné výživné.
 *
 * HPP 35 000 Kč bez prohlášení, čistá mzda 25 690 Kč, bez vyživovaných osob:
 * nezabavitelná částka 14 102 Kč, zbytek 11 588 → 11 586 Kč, třetina
 * 3 862 Kč (odvození viz {@see PayrollE2eHpp2DeductionTest}).
 */
#[Group('integration')]
final class PayrollE2eHpp2DeductionMonthsTest extends TestCase
{
    use EnforcementRunFixtureTrait;
    use IsolatedSupplierTrait;

    private const MONTHS = [
        ['2026-01-01', '2026-02-10'],
        ['2026-02-01', '2026-03-10'],
        ['2026-03-01', '2026-04-10'],
        ['2026-04-01', '2026-05-11'],
    ];

    protected function tearDown(): void
    {
        $this->tearDownEnforcementRun();
    }

    /**
     * Nepřednostní pohledávka 10 000 Kč se umořuje částkou vyplacenou
     * oprávněnému: paušál 50 Kč měsíčně jde k tíži povinného (§ 270 odst. 2
     * o. s. ř.: „Plátce mzdy má vůči povinnému nárok na paušálně stanovenou
     * náhradu nákladů“) a dluh nesnižuje.
     *
     * Leden: sraženo 3 862, oprávněnému 3 812, zbývá 6 188 Kč.
     * Únor: sraženo 3 862, oprávněnému 3 812, zbývá 2 376 Kč.
     * Březen: třetina 3 862 Kč dluh 2 376 Kč s paušálem pokryje.
     */
    public function testClaimIsReducedByAmountsPaidToCreditorNotByEmployerFee(): void
    {
        $this->bootEnforcementRun();
        $claim = $this->claimIn('non_priority', '2026-01-12', 1_000_000);

        $paid = [];
        foreach (array_slice(self::MONTHS, 0, 2) as [$period, $payday]) {
            $run = $this->month($period, $payday);
            self::assertSame(386_200, $run['enforcement']['total_withheld_minor_units']);
            self::assertSame(5_000, $run['enforcement']['employer_flat_fee_minor_units']);
            $paid[] = $run['enforcement']['allocations'][0]['total_minor_units'];
            $this->approveEnforcementRun($run);
        }
        self::assertSame([381_200, 381_200], $paid);

        $march = $this->month(...self::MONTHS[2]);
        $input = array_column($march['person']['enforcement']['input']['claims'], null, 'id');
        self::assertSame(237_600, $input[$claim]['outstanding_minor_units'], 'Zůstatek po dvou měsících: 10 000 − 2 × 3 812.');
    }

    /**
     * Běžné výživné 2 000 Kč měsíčně zaevidované částkou jedné měsíční dávky:
     * v lednu se srazí (z druhé třetiny, paušál plátce mzdy 50 Kč navíc
     * z volné první třetiny), v únoru už evidence nic nenárokuje. Běh to nesmí
     * přejít mlčky (plátce mzdy za neprovedené srážky ručí, § 292 o. s. ř.):
     * únorový běh nese varování s povinným potvrzením a odkazem na případ.
     */
    public function testExhaustedCurrentMaintenanceStopsApprovalInsteadOfSilence(): void
    {
        $this->bootEnforcementRun();
        $this->claimIn('current_maintenance', '2026-01-12', 200_000, 200_000);

        $january = $this->month(...self::MONTHS[0]);
        self::assertSame(200_000, $january['enforcement']['allocations'][0]['total_minor_units']);
        self::assertSame(205_000, $january['enforcement']['total_withheld_minor_units']);
        self::assertNotContains('enforcement_current_maintenance_exhausted', $january['validation_codes']);
        $this->approveEnforcementRun($january);

        $february = $this->month(...self::MONTHS[1]);
        self::assertSame(0, $february['enforcement']['total_withheld_minor_units']);
        $warnings = array_values(array_filter(
            $february['validations'],
            static fn (array $row): bool => $row['code'] === 'enforcement_current_maintenance_exhausted',
        ));
        self::assertCount(1, $warnings, 'Běh o nesraženém běžném výživném mlčí.');
        self::assertSame('warning', $warnings[0]['severity']);
        self::assertTrue((bool) $warnings[0]['requires_override']);
        self::assertSame("/payroll/enforcement?person={$this->employeeId}", $warnings[0]['remediation_path']);
        self::assertStringContainsString('Běžné výživné se sráží každý měsíc', $warnings[0]['message']);
    }

    /** Nepřednostní pohledávka ani dlužné výživné po doplacení varování nevyvolají. */
    public function testPaidOffOrdinaryClaimRaisesNoMaintenanceWarning(): void
    {
        $this->bootEnforcementRun();
        $this->claimIn('maintenance_arrears', '2026-01-12', 200_000, 200_000);

        $january = $this->month(...self::MONTHS[0]);
        self::assertSame(200_000, $january['enforcement']['allocations'][0]['total_minor_units']);
        self::assertSame(205_000, $january['enforcement']['total_withheld_minor_units']);
        $this->approveEnforcementRun($january);

        $february = $this->month(...self::MONTHS[1]);
        self::assertSame(0, $february['enforcement']['total_withheld_minor_units']);
        self::assertNotContains('enforcement_current_maintenance_exhausted', $february['validation_codes']);
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
