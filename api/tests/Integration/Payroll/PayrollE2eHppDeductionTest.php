<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\EnforcementRunFixtureTrait;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Exekuční srážky z HPP 35 000 Kč (červen 2026) s vyživovanou osobou,
 * ručně přepočtené podle sady 2026.
 *
 * Čistá mzda: bez prohlášení, SP 35 000 × 7,1 % = 2 485, ZP 4 725 / 3
 * = 1 575, záloha 35 000 × 15 % = 5 250 → 25 690 Kč.
 * Nezabavitelná částka (§ 278 o. s. ř., nař. vlády č. 595/2006 Sb. ve znění
 * č. 548/2025 Sb.): na povinného 85 % z 16 590 = 14 101,50 Kč, na
 * vyživovanou osobu čtvrtina z toho = 3 525,375 Kč; součet 17 626,875
 * → nahoru na koruny 17 627 Kč. Zbytek 25 690 − 17 627 = 8 063 Kč je pod
 * hranicí plně zabavitelného zbytku (31 521 Kč), dělí se na třetiny ze
 * základu dělitelného třemi 8 061 Kč: třetina 2 687 Kč.
 * První třetina jde pohledávkám podle pořadí doručení bez ohledu na druh,
 * druhá jen přednostním (§ 279 a § 280 o. s. ř.).
 */
#[Group('integration')]
final class PayrollE2eHppDeductionTest extends TestCase
{
    use EnforcementRunFixtureTrait;
    use IsolatedSupplierTrait;

    protected function setUp(): void
    {
        $this->bootEnforcementRun();
    }

    protected function tearDown(): void
    {
        $this->tearDownEnforcementRun();
    }

    public function testNonPriorityClaimTakesOneThirdAfterDependantAllowance(): void
    {
        $this->dependant();
        $caseId = $this->seedRunCase('remit');
        $this->seedRunParty($caseId, 'beneficiary', 'Syntetický oprávněný', null);
        $this->seedRunClaim($caseId);
        $this->seedRunMonthEvidence();

        $run = $this->calculateEnforcementRun();

        self::assertSame(2_569_000, $run['statutory']['people'][0]['net_payable_minor_units']);
        self::assertSame(1_762_700, $run['enforcement']['protected_amount_minor_units']);
        self::assertSame(268_700, $run['enforcement']['third_minor_units']);
        self::assertSame(268_700, (int) $run['enforcement']['total_withheld_minor_units'], CanonicalJson::encode($run['enforcement']));
        // Paušál plátce mzdy 50 Kč jde přednostně ze sražené částky
        // (vyhl. č. 485/2000 Sb.), oprávněnému zbude 2 637 Kč.
        self::assertSame(5_000, $run['enforcement']['employer_flat_fee_minor_units']);
        self::assertSame(263_700, $run['enforcement']['allocations'][0]['total_minor_units']);
        self::assertSame(2_569_000 - 268_700, $run['enforcement']['employee_payment_minor_units']);
    }

    /**
     * Nepřednostní pohledávka doručená dřív (15. 1.) a jiná přednostní
     * (např. pojistné) později (10. 2.): první třetina patří nepřednostní (po paušálu
     * 2 637 Kč), druhá třetina 2 687 Kč přednostní. Sraženo 5 374 Kč.
     */
    public function testEarlierNonPriorityTakesFirstThirdPriorityTakesSecond(): void
    {
        $this->dependant();
        $nonPriority = $this->claimIn('non_priority', '2026-01-15');
        $priority = $this->claimIn('other_priority', '2026-02-10');
        $this->seedRunMonthEvidence();

        $run = $this->calculateEnforcementRun();

        self::assertSame(537_400, (int) $run['enforcement']['total_withheld_minor_units'], CanonicalJson::encode($run['enforcement']));
        $allocations = array_column($run['enforcement']['allocations'], null, 'claim_id');
        self::assertSame([263_700, 0], [$allocations[$nonPriority]['first_pool_minor_units'], $allocations[$nonPriority]['second_pool_minor_units']]);
        self::assertSame([0, 268_700], [$allocations[$priority]['first_pool_minor_units'], $allocations[$priority]['second_pool_minor_units']]);
        self::assertSame(2_569_000 - 537_400, $run['enforcement']['employee_payment_minor_units']);
    }

    /**
     * Přednostní pohledávka doručená dřív bere obě třetiny (první po
     * paušálu 2 637 + druhá 2 687 = 5 324 Kč), na pozdější nepřednostní
     * pohledávku nezbude nic.
     */
    public function testEarlierPriorityMaintenanceTakesBothThirds(): void
    {
        $this->dependant();
        $priority = $this->claimIn('other_priority', '2026-01-10');
        $nonPriority = $this->claimIn('non_priority', '2026-02-15');
        $this->seedRunMonthEvidence();

        $run = $this->calculateEnforcementRun();

        self::assertSame(537_400, (int) $run['enforcement']['total_withheld_minor_units'], CanonicalJson::encode($run['enforcement']));
        $allocations = array_column($run['enforcement']['allocations'], null, 'claim_id');
        self::assertSame(532_400, $allocations[$priority]['total_minor_units']);
        self::assertSame(268_700, $allocations[$priority]['second_pool_minor_units']);
        self::assertSame(0, $allocations[$nonPriority]['total_minor_units'] ?? 0);
    }

    /** Vytvoří pohledávku v novém řízení a vrátí její klíč (claim_id výsledku). */
    private function claimIn(string $category, string $deliveredOn, ?int $maintenanceMinor = null): string
    {
        $caseId = $this->seedRunCase('remit');
        $this->seedRunParty($caseId, 'beneficiary', 'Syntetický oprávněný ' . $category, null);
        $claimId = $this->seedRunClaim($caseId, $category, 10_000_000, $deliveredOn, $maintenanceMinor);
        $statement = $this->db->pdo()->prepare('SELECT claim_key FROM payroll_enforcement_claims WHERE supplier_id = ? AND id = ?');
        $statement->execute([$this->supplierId, $claimId]);

        return (string) $statement->fetchColumn();
    }

    private function dependant(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_dependants
                (supplier_id, employee_id, dependant_key, dependant_kind, valid_from,
                 eligibility_verified, excluded_for_maintenance)
             VALUES (?, ?, ?, "dependant", "2026-01-01", 1, 0)',
        )->execute([$this->supplierId, $this->employeeId, 'dependant-' . bin2hex(random_bytes(4))]);
    }
}
