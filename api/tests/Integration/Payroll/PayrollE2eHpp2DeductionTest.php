<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollDeductionAgreementRepository;
use MyInvoice\Repository\Payroll\PayrollEnforcementRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Service\Payroll\Net\DeductionAgreementStatus;
use MyInvoice\Service\Payroll\Net\DeductionAgreementTerms;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\EnforcementRunFixtureTrait;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Srážky ze mzdy HPP za leden až březen 2026, ručně přepočtené podle
 * předpisů (ne podle výstupu aplikace).
 *
 * Čistá mzda (§ 277 odst. 1 o. s. ř.): HPP 35 000 Kč bez prohlášení,
 * SP 35 000 × 7,1 % = 2 485, ZP 35 000 × 4,5 % = 1 575, záloha 35 000 × 15 %
 * = 5 250 → 25 690 Kč. U mzdy 80 000 Kč: SP 5 680, ZP 3 600, záloha 12 000
 * → 58 720 Kč.
 *
 * Nezabavitelná částka 2026 (nař. vlády č. 595/2006 Sb. ve znění
 * č. 548/2025 Sb., hodnoty k 1. 1. 2026 podle § 4): životní minimum
 * jednotlivce 4 860 Kč (nař. vlády č. 361/2025 Sb.) + normativní nájemné
 * jednočlenné domácnosti v obci nad 70 000 obyvatel 9 430 Kč (sdělení MPSV
 * č. 526/2025 Sb.) + energetický paušál 2 300 Kč (§ 32 odst. 2 písm. a)
 * zák. č. 151/2025 Sb.) = 16 590 Kč. Na povinného 85 % = 14 101,50 Kč,
 * na každou vyživovanou osobu čtvrtina = 3 525,375 Kč; úhrn se zaokrouhlí
 * na koruny nahoru (§ 3). Manžel se nepočítá, ledaže je doložen důchod
 * (§ 1 odst. 1 a 2). Plně zabavitelný je zbytek nad 1,9 × 16 590
 * = 31 521 Kč (§ 2).
 *
 * Zbytek čisté mzdy se zaokrouhlí dolů na koruny dělitelné třemi, jedna
 * třetina jde všem pohledávkám podle pořadí doručení plátci mzdy, druhá jen
 * přednostním (§ 279 odst. 1 a 2, § 280 o. s. ř.). Paušál plátce mzdy 50 Kč
 * měsíčně se uspokojuje z první třetiny před ostatními (§ 279 odst. 1 věta
 * poslední o. s. ř., § 4d vyhl. č. 485/2000 Sb.).
 */
#[Group('integration')]
final class PayrollE2eHpp2DeductionTest extends TestCase
{
    use EnforcementRunFixtureTrait;
    use IsolatedSupplierTrait;

    protected function tearDown(): void
    {
        $this->tearDownEnforcementRun();
    }

    /**
     * Dvě vyživované osoby, nepřednostní pohledávka doručená 15. 1.
     * a přednostní (pojistné) doručená 3. 2., mzda za únor 2026.
     *
     * Nezabavitelná částka 14 101,50 × 6/4 = 21 152,25 → 21 153 Kč. Zbytek
     * 25 690 − 21 153 = 4 537 → 4 536, třetina 1 512 Kč. První třetina patří
     * dřív doručené nepřednostní pohledávce (po paušálu 1 462 Kč), druhá
     * přednostní. Sraženo 3 024 Kč, k výplatě 22 666 Kč.
     */
    public function testTwoDependantsNonPriorityFirstThirdPrioritySecondThird(): void
    {
        $this->bootEnforcementRun();
        $this->dependant();
        $this->dependant();
        $nonPriority = $this->claimIn('non_priority', '2026-01-15');
        $priority = $this->claimIn('other_priority', '2026-02-03');

        $run = $this->month('2026-02-01', '2026-03-10');

        $this->assertEnforcement($run, [
            'net' => 2_569_000,
            'protected' => 2_115_300,
            'third' => 151_200,
            'excess' => 0,
            'withheld' => 302_400,
            'fee' => 5_000,
            'payment' => 2_266_600,
        ]);
        $allocations = array_column($run['enforcement']['allocations'], null, 'claim_id');
        self::assertSame([146_200, 0], [$allocations[$nonPriority]['first_pool_minor_units'], $allocations[$nonPriority]['second_pool_minor_units']]);
        self::assertSame([0, 151_200], [$allocations[$priority]['first_pool_minor_units'], $allocations[$priority]['second_pool_minor_units']]);
        self::assertSame(2_266_600, $run['person']['payable_after_enforcement_minor']);
    }

    /**
     * Manžel bez doloženého důchodu se do nezabavitelné částky nepočítá
     * (§ 1 odst. 1 nař. vlády č. 595/2006 Sb.), dítě ano: 14 101,50 × 5/4
     * = 17 626,875 → 17 627 Kč, třetina (25 690 − 17 627 = 8 063 → 8 061) / 3
     * = 2 687 Kč.
     */
    public function testSpouseWithoutPensionProofDoesNotRaiseProtectedAmount(): void
    {
        $this->bootEnforcementRun();
        $this->dependant();
        $this->spouse('not_documented');
        $this->claimIn('non_priority', '2026-01-15');

        $run = $this->month('2026-01-01', '2026-02-10');

        $this->assertEnforcement($run, [
            'net' => 2_569_000,
            'protected' => 1_762_700,
            'third' => 268_700,
            'excess' => 0,
            'withheld' => 268_700,
            'fee' => 5_000,
            'payment' => 2_300_300,
        ]);
    }

    /**
     * Manžel s doloženým starobním důchodem (§ 1 odst. 2 nař. vlády
     * č. 595/2006 Sb.) čtvrtinu zakládá: dítě + manžel = 14 101,50 × 6/4
     * → 21 153 Kč, třetina 1 512 Kč.
     */
    public function testSpouseWithDocumentedPensionRaisesProtectedAmount(): void
    {
        $this->bootEnforcementRun();
        $this->dependant();
        $this->spouse('documented');
        $this->claimIn('non_priority', '2026-01-15');

        $run = $this->month('2026-01-01', '2026-02-10');

        $this->assertEnforcement($run, [
            'net' => 2_569_000,
            'protected' => 2_115_300,
            'third' => 151_200,
            'excess' => 0,
            'withheld' => 151_200,
            'fee' => 5_000,
            'payment' => 2_417_800,
        ]);
    }

    /**
     * Tři nepřednostní exekuce podle pořadí doručení (§ 280 odst. 1 a 5
     * o. s. ř.), bez vyživovaných osob, mzda za březen 2026.
     *
     * Nezabavitelná částka 14 101,50 → 14 102 Kč; zbytek 11 588 → 11 586,
     * třetina 3 862 Kč. Z ní nejdřív paušál 50 Kč, pak první pohledávka
     * (doběh, zbývá 1 000 Kč) celá, druhé zbylých 2 812 Kč, na třetí nic.
     */
    public function testThreeEnforcementsAreSatisfiedInOrderOfDelivery(): void
    {
        $this->bootEnforcementRun();
        $first = $this->claimIn('non_priority', '2026-01-08', 100_000);
        $second = $this->claimIn('non_priority', '2026-01-20');
        $third = $this->claimIn('non_priority', '2026-02-03', 5_000_000);

        $run = $this->month('2026-03-01', '2026-04-10');

        $this->assertEnforcement($run, [
            'net' => 2_569_000,
            'protected' => 1_410_200,
            'third' => 386_200,
            'excess' => 0,
            'withheld' => 386_200,
            'fee' => 5_000,
            'payment' => 2_182_800,
        ]);
        $allocations = array_column($run['enforcement']['allocations'], null, 'claim_id');
        self::assertSame(100_000, $allocations[$first]['total_minor_units']);
        self::assertSame(281_200, $allocations[$second]['total_minor_units']);
        self::assertArrayNotHasKey($third, $allocations);
        self::assertFalse($run['enforcement']['four_enforcement_rule_applied']);
    }

    /**
     * Čtyři doručené exekuce: srážejí se vždy dvě třetiny, i když jsou
     * všechny pohledávky nepřednostní, a druhá třetina se připočte k první
     * (§ 279 odst. 4 a 6 o. s. ř.). 2 × 3 862 = 7 724 Kč podle pořadí:
     * první 1 000 Kč, druhé zbytek po paušálu 6 674 Kč.
     */
    public function testFourEnforcementsWithholdBothThirdsForNonPriorityClaims(): void
    {
        $this->bootEnforcementRun();
        $first = $this->claimIn('non_priority', '2026-01-08', 100_000);
        $second = $this->claimIn('non_priority', '2026-01-20');
        $this->claimIn('non_priority', '2026-02-03', 5_000_000);
        $this->claimIn('non_priority', '2026-02-17', 2_000_000);

        $run = $this->month('2026-03-01', '2026-04-10');

        $this->assertEnforcement($run, [
            'net' => 2_569_000,
            'protected' => 1_410_200,
            'third' => 386_200,
            'excess' => 0,
            'withheld' => 772_400,
            'fee' => 5_000,
            'payment' => 1_796_600,
        ]);
        self::assertTrue($run['enforcement']['four_enforcement_rule_applied']);
        $allocations = array_column($run['enforcement']['allocations'], null, 'claim_id');
        self::assertSame(100_000, $allocations[$first]['total_minor_units']);
        self::assertSame(667_400, $allocations[$second]['total_minor_units']);
        self::assertCount(2, $allocations);
    }

    /**
     * Mzda 80 000 Kč, čistá 58 720 Kč, bez vyživovaných osob, jedna
     * nepřednostní pohledávka.
     *
     * Zbytek 58 720 − 14 102 = 44 618 Kč; do 31 521 Kč se dělí na třetiny
     * po 10 507 Kč, 13 097 Kč nad hranicí je plně zabavitelných a bez
     * přednostní pohledávky se připočte k první třetině (§ 279 odst. 3
     * o. s. ř.). Sraženo 10 507 + 13 097 = 23 604 Kč, povinnému zůstane
     * nezabavitelná částka a druhá třetina: 35 116 Kč.
     */
    public function testFullyAttachableExcessGoesToNonPriorityClaim(): void
    {
        $this->bootEnforcementRun(8_000_000);
        $claim = $this->claimIn('non_priority', '2026-01-15', 50_000_000);

        $run = $this->month('2026-01-01', '2026-02-10', 8_000_000);

        $this->assertEnforcement($run, [
            'net' => 5_872_000,
            'protected' => 1_410_200,
            'third' => 1_050_700,
            'excess' => 1_309_700,
            'withheld' => 2_360_400,
            'fee' => 5_000,
            'payment' => 3_511_600,
        ]);
        self::assertSame(2_355_400, $run['enforcement']['allocations'][0]['total_minor_units']);
        self::assertSame($claim, $run['enforcement']['allocations'][0]['claim_id']);
    }

    /**
     * Totéž s později doručenou přednostní pohledávkou 15 000 Kč: ta se
     * uspokojí z druhé třetiny 10 507 Kč a z plně zabavitelné části
     * 4 493 Kč, zbytek plně zabavitelné části 8 604 Kč se připočte k první
     * třetině (§ 279 odst. 3 věta druhá o. s. ř.). Nepřednostní pohledávka
     * 10 507 + 8 604 − 50 = 19 061 Kč. Sraženo 34 111 Kč, povinnému zůstane
     * jen nezabavitelná částka a nic z druhé třetiny: 24 609 Kč.
     */
    public function testFullyAttachableExcessCoversPriorityClaimFirst(): void
    {
        $this->bootEnforcementRun(8_000_000);
        $nonPriority = $this->claimIn('non_priority', '2026-01-15', 50_000_000);
        $priority = $this->claimIn('other_priority', '2026-01-28', 1_500_000);

        $run = $this->month('2026-01-01', '2026-02-10', 8_000_000);

        $this->assertEnforcement($run, [
            'net' => 5_872_000,
            'protected' => 1_410_200,
            'third' => 1_050_700,
            'excess' => 1_309_700,
            'withheld' => 3_411_100,
            'fee' => 5_000,
            'payment' => 2_460_900,
        ]);
        $allocations = array_column($run['enforcement']['allocations'], null, 'claim_id');
        self::assertSame(1_500_000, $allocations[$priority]['second_pool_minor_units']);
        self::assertSame(1_906_100, $allocations[$nonPriority]['first_pool_minor_units']);
    }

    /**
     * Běžné a dlužné výživné vedle dřív doručené nepřednostní exekuce,
     * jedna další vyživovaná osoba (dítě, pro které se výživné vymáhá, se
     * podle § 1 odst. 2 věty poslední nař. vlády č. 595/2006 Sb.
     * nezapočítává). Třetina 2 687 Kč.
     *
     * Druhá třetina: nejdřív běžné výživné 2 000 Kč, pak nedoplatek 687 Kč
     * (§ 280 odst. 2 a 3 o. s. ř.). První třetina podle pořadí: dřív
     * doručená nepřednostní pohledávka celá (po paušálu 2 637 Kč), na
     * nedoplatek výživného z ní nezbude. Sraženo 5 374 Kč.
     */
    public function testCurrentMaintenanceThenArrearsFromSecondThird(): void
    {
        $this->bootEnforcementRun();
        $this->dependant();
        $this->dependant(excludedForMaintenance: true);
        $nonPriority = $this->claimIn('non_priority', '2026-01-05');
        $current = $this->claimIn('current_maintenance', '2026-01-12', 200_000, 200_000);
        $arrears = $this->claimIn('maintenance_arrears', '2026-01-12', 3_000_000, 200_000);

        $run = $this->month('2026-01-01', '2026-02-10');

        $this->assertEnforcement($run, [
            'net' => 2_569_000,
            'protected' => 1_762_700,
            'third' => 268_700,
            'excess' => 0,
            'withheld' => 537_400,
            'fee' => 5_000,
            'payment' => 2_031_600,
        ]);
        $allocations = array_column($run['enforcement']['allocations'], null, 'claim_id');
        self::assertSame([0, 200_000], [$allocations[$current]['first_pool_minor_units'], $allocations[$current]['second_pool_minor_units']]);
        self::assertSame([0, 68_700], [$allocations[$arrears]['first_pool_minor_units'], $allocations[$arrears]['second_pool_minor_units']]);
        self::assertSame([263_700, 0], [$allocations[$nonPriority]['first_pool_minor_units'], $allocations[$nonPriority]['second_pool_minor_units']]);
    }

    /**
     * Běžné výživné dvou oprávněných (4 000 a 2 000 Kč), které druhá
     * třetina 2 687 Kč nekryje: dělí se poměrně podle výše běžného
     * výživného (§ 280 odst. 4 o. s. ř.): 2 687 × 4/6 = 1 791,33 Kč
     * a 2 687 × 2/6 = 895,67 Kč (zákon zaokrouhlení podílů neurčuje, dělí
     * se na haléře). Neuspokojený zbytek jde do první třetiny podle pořadí
     * (§ 279 odst. 1 věta třetí): paušál 50 Kč, dřív doručené výživné
     * zbylých 2 208,67 Kč, druhé 2 687 − 50 − 2 208,67 = 428,33 Kč. První
     * oprávněný dostane celých 4 000 Kč, druhý 1 324 Kč.
     */
    public function testCurrentMaintenanceOfTwoCreditorsIsSplitByMonthlyAmounts(): void
    {
        $this->bootEnforcementRun();
        $this->dependant();
        $first = $this->claimIn('current_maintenance', '2026-01-12', 400_000, 400_000);
        $second = $this->claimIn('current_maintenance', '2026-01-20', 200_000, 200_000);

        $run = $this->month('2026-01-01', '2026-02-10');

        $this->assertEnforcement($run, [
            'net' => 2_569_000,
            'protected' => 1_762_700,
            'third' => 268_700,
            'excess' => 0,
            'withheld' => 537_400,
            'fee' => 5_000,
            'payment' => 2_031_600,
        ]);
        $allocations = array_column($run['enforcement']['allocations'], null, 'claim_id');
        self::assertSame([220_867, 179_133], [$allocations[$first]['first_pool_minor_units'], $allocations[$first]['second_pool_minor_units']]);
        self::assertSame([42_833, 89_567], [$allocations[$second]['first_pool_minor_units'], $allocations[$second]['second_pool_minor_units']]);
        self::assertSame(400_000, $allocations[$first]['total_minor_units']);
        self::assertSame(132_400, $allocations[$second]['total_minor_units']);
    }

    /**
     * Schválené oddlužení plněním splátkového kalendáře: plátce mzdy sráží
     * ve stejném rozsahu jako pro přednostní pohledávky, tedy obě třetiny
     * (§ 398 odst. 3 a § 406 odst. 3 písm. d) zák. č. 182/2006 Sb.), a posílá
     * je insolvenčnímu správci. Paušál plátci mzdy nenáleží (nejde o výkon
     * rozhodnutí) a starší exekuce se neprovádí (§ 109 odst. 1 písm. c) IZ).
     *
     * Dvě vyživované osoby: nezabavitelná částka 21 153 Kč, třetina 1 512 Kč,
     * sraženo 3 024 Kč, k výplatě 22 666 Kč.
     */
    public function testApprovedDebtReliefWithholdsBothThirdsForAdministrator(): void
    {
        $this->bootEnforcementRun();
        $this->dependant();
        $this->dependant();
        $this->claimIn('non_priority', '2026-01-15');
        $this->wage('2026-02-01');
        $this->approvedDebtRelief('2026-02', 'approved_standard');

        $run = $this->calculateEnforcementRun('2026-02-01', '2026-03-10');

        $this->assertEnforcement($run, [
            'net' => 2_569_000,
            'protected' => 2_115_300,
            'third' => 151_200,
            'excess' => 0,
            'withheld' => 302_400,
            'fee' => 0,
            'payment' => 2_266_600,
        ]);
        self::assertTrue($run['enforcement']['insolvency_applied']);
        self::assertSame(
            [['claim_id' => 'insolvency-administrator', 'first' => 0, 'second' => 302_400]],
            array_map(static fn (array $row): array => [
                'claim_id' => $row['claim_id'],
                'first' => $row['first_pool_minor_units'],
                'second' => $row['second_pool_minor_units'],
            ], $run['enforcement']['allocations']),
        );
        self::assertSame([], $this->blockers($run));
    }

    /**
     * Oddlužení u vyšší mzdy 80 000 Kč (čistá 58 720 Kč): obě třetiny
     * i plně zabavitelná část, 2 × 10 507 + 13 097 = 34 111 Kč. Dlužníkovi
     * zůstane nezabavitelná částka 14 102 Kč a třetí třetina 10 507 Kč,
     * tedy 24 609 Kč.
     */
    public function testApprovedDebtReliefTakesFullyAttachableExcess(): void
    {
        $this->bootEnforcementRun(8_000_000);
        $this->wage('2026-01-01', 8_000_000);
        $this->approvedDebtRelief('2026-01', 'approved_standard');

        $run = $this->calculateEnforcementRun('2026-01-01', '2026-02-10');

        $this->assertEnforcement($run, [
            'net' => 5_872_000,
            'protected' => 1_410_200,
            'third' => 1_050_700,
            'excess' => 1_309_700,
            'withheld' => 3_411_100,
            'fee' => 0,
            'payment' => 2_460_900,
        ]);
    }

    /**
     * Soud určil jinou výši měsíční splátky (§ 398 odst. 5 IZ): 2 000 Kč,
     * méně než zákonné dvě třetiny 3 024 Kč. Sráží se určená částka.
     */
    public function testCourtDeterminedInstallmentIsWithheldInsteadOfStatutoryExtent(): void
    {
        $this->bootEnforcementRun();
        $this->dependant();
        $this->dependant();
        $this->wage('2026-03-01');
        $this->approvedDebtRelief('2026-03', 'court_determined_amount', 200_000);

        $run = $this->calculateEnforcementRun('2026-03-01', '2026-04-10');

        $this->assertEnforcement($run, [
            'net' => 2_569_000,
            'protected' => 2_115_300,
            'third' => 151_200,
            'excess' => 0,
            'withheld' => 200_000,
            'fee' => 0,
            'payment' => 2_369_000,
        ]);
    }

    /**
     * Dohoda o srážkách ze mzdy (§ 146 písm. b) ZP, § 2045 OZ) bez exekuce:
     * splátka 5 000 Kč měsíčně. Srážet lze jen za podmínek výkonu rozhodnutí
     * (§ 148 odst. 2 ZP), tedy nejvýš jednu třetinu zbytku čisté mzdy po
     * nezabavitelné částce: 3 862 Kč. Zbylých 1 138 Kč zůstane nesraženo,
     * k výplatě 25 690 − 3 862 = 21 828 Kč.
     */
    public function testDeductionAgreementIsCappedByOneThird(): void
    {
        $this->bootEnforcementRun();
        $agreement = $this->agreement('Splátka zápůjčky', 500_000, '2026-01-05');

        $run = $this->month('2026-01-01', '2026-02-10');

        self::assertSame(0, (int) $run['enforcement']['total_withheld_minor_units']);
        $netPay = $run['statutory']['people'][0]['net_pay'];
        self::assertSame(2_569_000, $netPay['net_before_deductions_minor_units']);
        self::assertSame(386_200, $netPay['deducted_minor_units'], CanonicalJson::encode($netPay['deductions']));
        self::assertSame(
            ['agreement:' . $agreement, 386_200, 113_800],
            [
                $netPay['deductions'][0]['deduction_reference'],
                $netPay['deductions'][0]['applied_minor_units'],
                $netPay['deductions'][0]['unapplied_minor_units'],
            ],
        );
        self::assertSame(2_182_800, $run['person']['payable_after_enforcement_minor']);
    }

    /**
     * Dohoda o srážkách doručená (uzavřená) 5. 1. a exekuce doručená 15. 1.:
     * pořadí se řídí dnem doručení (§ 149 odst. 1 ZP, § 280 odst. 5 o. s. ř.).
     * Z třetiny 3 862 Kč dostane dohoda svých 2 000 Kč, exekuce zbylých
     * 1 862 Kč (oprávněný po paušálu 1 812 Kč). K výplatě 21 828 Kč.
     */
    public function testEarlierAgreementPrecedesLaterEnforcement(): void
    {
        $this->bootEnforcementRun();
        $this->agreement('Splátka zápůjčky', 200_000, '2026-01-05');
        $claim = $this->claimIn('non_priority', '2026-01-15');

        $run = $this->month('2026-01-01', '2026-02-10');

        $this->assertEnforcement($run, [
            'net' => 2_569_000,
            'protected' => 1_410_200,
            'third' => 386_200,
            'excess' => 0,
            'withheld' => 186_200,
            'fee' => 5_000,
            'payment' => 2_382_800,
        ]);
        self::assertSame(181_200, $run['enforcement']['allocations'][0]['total_minor_units']);
        self::assertSame($claim, $run['enforcement']['allocations'][0]['claim_id']);
        self::assertSame(200_000, $run['statutory']['people'][0]['net_pay']['deducted_minor_units']);
        self::assertSame(2_182_800, $run['person']['payable_after_enforcement_minor']);
    }

    /**
     * Opačné pořadí: exekuce doručená 5. 1. vyčerpá celou třetinu, na dohodu
     * doručenou 20. 1. nezbude nic (druhá třetina dohodě nepatří).
     */
    public function testLaterAgreementGetsNothingWhenEnforcementUsesTheThird(): void
    {
        $this->bootEnforcementRun();
        $this->claimIn('non_priority', '2026-01-05');
        $this->agreement('Splátka zápůjčky', 200_000, '2026-01-20');

        $run = $this->month('2026-01-01', '2026-02-10');

        $this->assertEnforcement($run, [
            'net' => 2_569_000,
            'protected' => 1_410_200,
            'third' => 386_200,
            'excess' => 0,
            'withheld' => 386_200,
            'fee' => 5_000,
            'payment' => 2_182_800,
        ]);
        self::assertSame(0, $run['statutory']['people'][0]['net_pay']['deducted_minor_units']);
        self::assertSame(2_182_800, $run['person']['payable_after_enforcement_minor']);
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

    /**
     * @param array<string,mixed> $run
     * @param array{net:int,protected:int,third:int,excess:int,withheld:int,fee:int,payment:int} $expected
     */
    private function assertEnforcement(array $run, array $expected): void
    {
        $result = $run['enforcement'];
        self::assertSame('supported', $result['status'], CanonicalJson::encode($result['issues']));
        self::assertSame($expected, [
            'net' => $result['garnishable_income_minor_units'],
            'protected' => $result['protected_amount_minor_units'],
            'third' => $result['third_minor_units'],
            'excess' => $result['fully_attachable_excess_minor_units'],
            'withheld' => $result['total_withheld_minor_units'],
            'fee' => $result['employer_flat_fee_minor_units'],
            'payment' => $result['employee_payment_minor_units'],
        ], 'Srážky nesouhlasí s ručním přepočtem.');
        self::assertNotContains('enforcement_manual_review', $run['validation_codes']);
    }

    /**
     * @param array<string,mixed> $run
     * @return list<string>
     */
    private function blockers(array $run): array
    {
        return array_values(array_map(
            static fn (array $validation): string => $validation['code'] . ': ' . $validation['message'],
            array_filter($run['validations'], static fn (array $validation): bool => $validation['severity'] === 'blocker'),
        ));
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

    private function dependant(bool $excludedForMaintenance = false): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_dependants
                (supplier_id, employee_id, dependant_key, dependant_kind, valid_from,
                 eligibility_verified, excluded_for_maintenance)
             VALUES (?, ?, ?, "dependant", "2026-01-01", 1, ?)',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            'dependant-' . bin2hex(random_bytes(4)),
            $excludedForMaintenance ? 1 : 0,
        ]);
    }

    private function spouse(string $pensionEvidence): void
    {
        $documented = $pensionEvidence === 'documented';
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_enforcement_dependants
                (supplier_id, employee_id, dependant_key, dependant_kind, valid_from,
                 eligibility_verified, excluded_for_maintenance,
                 quarter_pension_evidence, quarter_pension_holder,
                 quarter_pension_kind, quarter_pension_documented_on)
             VALUES (?, ?, ?, "spouse_partner", "2026-01-01", 1, 0, ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $this->employeeId,
            'spouse-' . bin2hex(random_bytes(4)),
            $pensionEvidence,
            $documented ? 'spouse_partner' : null,
            $documented ? 'old_age' : null,
            $documented ? '2026-01-02' : null,
        ]);
    }

    /** Aktivní dohoda o srážkách ze mzdy; vrací její ID. */
    private function agreement(string $title, int $requestedMinor, string $deliveredOn): int
    {
        $created = $this->service(PayrollDeductionAgreementRepository::class)->create(
            $this->supplierId,
            $this->employeeId,
            DeductionAgreementTerms::fromRequest([
                'title' => $title,
                'deduction_kind' => 'other',
                'priority_no' => 100,
                'requested_minor' => $requestedMinor,
                'valid_from' => '2026-01-01',
                'delivered_on' => $deliveredOn,
            ]),
            DeductionAgreementStatus::Active,
            $this->actorId,
        );

        return (int) $created['id'];
    }

    /** Měsíční evidence se schváleným oddlužením a platebním pokynem správci. */
    private function approvedDebtRelief(string $period, string $mode, ?int $courtAmountMinor = null): void
    {
        $account = $this->service(PayrollInstitutionAccountRepository::class)->create($this->supplierId, [
            'institution_type' => 'other_recipient',
            'institution_code' => 'ISPRAVCE',
            'institution_name' => 'Syntetický insolvenční správce',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => '1234567890',
            'specific_symbol' => null,
            'constant_symbol' => null,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'synthetic:insolvency-administrator',
            'verified_on' => '2026-01-02',
        ], $this->actorId);
        $this->service(PayrollEnforcementRepository::class)->saveMonthEvidence(
            $this->supplierId,
            $this->employeeId,
            $period,
            [
                'claim_register_evidence_complete' => true,
                'dependants_evidence_complete' => true,
                'spouse_evidence_complete' => true,
                'pension_evidence' => 'none',
                'has_multiple_payers' => false,
                'protected_amount_override_minor_units' => null,
                'protected_amount_override_verified' => false,
                'insolvency_mode' => $mode,
                'insolvency_decision_verified' => true,
                'insolvency_recipient_verified' => true,
                'insolvency_employment_id' => $this->employmentId,
                'insolvency_institution_account_id' => (int) $account['id'],
                'insolvency_decision_document_id' => $this->seedDecisionDocument('debt-relief'),
                'court_determined_amount_minor_units' => $courtAmountMinor,
            ],
            $this->actorId,
            null,
        );
    }
}
