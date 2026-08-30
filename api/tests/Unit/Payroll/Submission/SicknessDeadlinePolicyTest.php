<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Sickness\SicknessBenefitKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessChannelCatalog;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use PHPUnit\Framework\TestCase;

/**
 * Lhůty NEMPRI a HZUPN a fail-closed cesty agendy.
 */
final class SicknessDeadlinePolicyTest extends TestCase
{
    private SicknessDeadlinePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new SicknessDeadlinePolicy();
    }

    /**
     * § 97 odst. 2 věta druhá: neprodleně PO UPLYNUTÍ prvních 14 dnů trvání
     * dočasné pracovní neschopnosti, tedy nejdřív 15. kalendářní den
     * (§ 26 odst. 1). Neschopnost od 1. 8. 2026 → 15. den je 15. 8. 2026,
     * což je sobota, takže termín padá na pondělí 17. 8.
     */
    public function testSicknessBenefitStartsOnFifteenthDayAndSkipsWeekend(): void
    {
        $window = $this->policy->forNempri(
            SicknessBenefitKind::Nem,
            '2026-08-01',
        );

        self::assertSame('2026-08-15', $window->earliestNotificationOn);
        self::assertSame('2026-08-17', $window->dueOn);
        self::assertSame(
            SicknessDeadlinePolicy::SOURCE_DERIVED_IMMEDIACY,
            $window->sourceStatus,
        );
        self::assertStringContainsString('§ 97 odst. 2', $window->legalReference);
    }

    /**
     * Padne-li 15. den na státní svátek podle zák. č. 245/2000 Sb., posouvá se
     * termín stejně jako o víkendu. Neschopnost od 20. 6. 2026 → 15. den je
     * sobota 4. 7. 2026, 5. 7. je neděle a zároveň svátek Cyrila a Metoděje,
     * 6. 7. je pondělní svátek Mistra Jana Husa, takže první pracovní den je
     * až úterý 7. 7.
     */
    public function testDeadlineSkipsPublicHolidays(): void
    {
        $window = $this->policy->forNempri(
            SicknessBenefitKind::Nem,
            '2026-06-20',
        );

        self::assertSame('2026-07-04', $window->earliestNotificationOn);
        self::assertSame('2026-07-07', $window->dueOn);
    }

    /**
     * § 97 odst. 5: „nejpozději v následující pracovní den po dni, který je
     * určen pro výplatu mezd a platů". Jediná lhůta téhle agendy, kterou zákon
     * vyjadřuje dnem — proto `statute_verified`.
     */
    public function testCompensatoryAllowanceIsDueNextWorkingDayAfterPayday(): void
    {
        $window = $this->policy->forNempri(
            SicknessBenefitKind::Vpm,
            '2026-08-01',
            null,
            '2026-08-14',
        );

        self::assertSame('2026-08-14', $window->earliestNotificationOn);
        // 14. 8. 2026 je pátek → následující pracovní den je pondělí 17. 8.
        self::assertSame('2026-08-17', $window->dueOn);
        self::assertSame(
            SicknessDeadlinePolicy::SOURCE_STATUTE_VERIFIED,
            $window->sourceStatus,
        );
    }

    public function testCompensatoryAllowanceFailsClosedWithoutPayday(): void
    {
        try {
            $this->policy->forNempri(SicknessBenefitKind::Vpm, '2026-08-01');
            self::fail('Bez výplatního dne nelze lhůtu podle § 97 odst. 5 spočítat.');
        } catch (SicknessException $exception) {
            self::assertSame(
                'nempri_vpm_payment_date_missing',
                $exception->validationCode,
            );
        }
    }

    /**
     * § 97 odst. 1 věta čtvrtá ve spojení s § 38b odst. 1: podpůrčí doba
     * u otcovské činí 2 týdny.
     */
    public function testPaternityWaitsForSupportPeriod(): void
    {
        $window = $this->policy->forNempri(
            SicknessBenefitKind::Opp,
            '2026-09-01',
        );

        self::assertSame('2026-09-15', $window->earliestNotificationOn);
    }

    /**
     * § 40 odst. 1 mluví o podpůrčí době „nejdéle 9 kalendářních dnů" — je to
     * horní mez. Skončila-li potřeba ošetřování dřív, běží lhůta od skutečného
     * skončení, ne od uplynutí devíti dnů.
     */
    public function testCareBenefitUsesActualEndWhenShorterThanSupportPeriod(): void
    {
        $window = $this->policy->forNempri(
            SicknessBenefitKind::Ose,
            '2026-09-01',
            '2026-09-04',
        );

        self::assertSame('2026-09-04', $window->earliestNotificationOn);
    }

    public function testLoneCarerGetsLongerCareSupportPeriod(): void
    {
        $window = $this->policy->forNempri(
            SicknessBenefitKind::Ose,
            '2026-09-01',
            null,
            null,
            true,
        );

        self::assertSame('2026-09-17', $window->earliestNotificationOn);
    }

    /**
     * § 97 odst. 3: hlásit se dá teprve tehdy, když je co hlásit. Skončení
     * neschopnosti 2026-08-22 je sobota → termín pondělí 24. 8.
     */
    public function testEndOfIncapacityReportIsDueOnFirstWorkingDay(): void
    {
        $window = $this->policy->forHzupn('2026-08-01', '2026-08-22');

        self::assertSame('2026-08-22', $window->earliestNotificationOn);
        self::assertSame('2026-08-24', $window->dueOn);
        self::assertStringContainsString('§ 97 odst. 3', $window->legalReference);
    }

    public function testEndOfIncapacityReportFailsClosedWhileIncapacityLasts(): void
    {
        try {
            $this->policy->forHzupn('2026-08-01', null);
            self::fail('Bez skončení neschopnosti povinnost podle § 97 odst. 3 nevzniká.');
        } catch (SicknessException $exception) {
            self::assertSame(
                'hzupn_incapacity_end_missing',
                $exception->validationCode,
            );
        }
    }

    /**
     * Otisk pravidel se musí změnit, jakmile se změní kterýkoli parametr —
     * jinak by evidence povinností tvrdila, že termín spočítala stejná verze
     * pravidel jako dřív.
     */
    public function testRulesetHashIsStableAcrossCalls(): void
    {
        $first = $this->policy->forNempri(SicknessBenefitKind::Nem, '2026-08-01');
        $second = $this->policy->forHzupn('2026-08-01', '2026-08-22');

        self::assertSame($first->rulesetHash, $second->rulesetHash);
        self::assertSame(
            SicknessDeadlinePolicy::RULESET_ID,
            $first->rulesetId,
        );
    }

    /**
     * VREP/APEP ČSSZ pro obě agendy přijímá, ale identifikátor třídy podání
     * pro ně v připnutém Podávacím a dotazovacím protokolu v1.47 není. Kanál
     * proto zůstává zavřený s vlastním důvodovým kódem, ne obecným
     * „nepodporováno".
     */
    public function testVrepChannelStaysClosedWithNamedReason(): void
    {
        $catalog = new SicknessChannelCatalog();

        self::assertSame('isds', $catalog->dispatchChannel());
        $catalog->assertDispatchable('isds');

        try {
            $catalog->assertDispatchable('vrep_apep');
            self::fail('Nedoložený kanál se nesmí otevřít.');
        } catch (SicknessException $exception) {
            self::assertSame(
                SicknessChannelCatalog::REASON_VREP_CLASS_UNDOCUMENTED,
                $exception->validationCode,
            );
            self::assertStringContainsString('5ffu6xk', $exception->getMessage());
        }
    }

    public function testUnknownChannelIsRefusedToo(): void
    {
        $catalog = new SicknessChannelCatalog();

        try {
            $catalog->assertDispatchable('email');
            self::fail('Neznámý kanál se nesmí otevřít.');
        } catch (SicknessException $exception) {
            self::assertSame(
                SicknessChannelCatalog::REASON_CHANNEL_UNKNOWN,
                $exception->validationCode,
            );
        }
    }
}
