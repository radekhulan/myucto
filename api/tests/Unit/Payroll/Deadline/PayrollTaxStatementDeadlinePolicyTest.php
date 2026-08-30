<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Deadline;

use MyInvoice\Service\Payroll\Deadline\PayrollTaxStatementDeadlinePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lhůty ročních vyúčtování — jediné místo, kde se datum počítá.
 *
 * Test drží tři věci, na kterých se dá lhůta splést: že „do dvou měsíců po
 * uplynutí kalendářního roku" končí 1. BŘEZNA (a ne posledním únorovým dnem),
 * že elektronické podání posouvá jen DPZVD6, a že víkend i svátek termín
 * posunou na nejbližší pracovní den (§ 33 odst. 4 DŘ).
 */
final class PayrollTaxStatementDeadlinePolicyTest extends TestCase
{
    private PayrollTaxStatementDeadlinePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PayrollTaxStatementDeadlinePolicy();
    }

    /**
     * Zálohová daň: § 38j odst. 5 ZDP, dva měsíce, elektronicky 20. března.
     *
     * @return list<array{int,string,string}> rok, zákonná lhůta, elektronická
     */
    public static function dependentActivityYears(): array
    {
        return [
            // 1. 3. 2024 je pátek, 20. 3. 2024 středa — nic se neposouvá.
            [2023, '2024-03-01', '2024-03-20'],
            // Přestupný rok 2024: konec roku je pořád 31. 12., lhůta tedy běží
            // od 1. 1. 2025 stejně jako po roce nepřestupném. 1. 3. 2025 padne
            // na sobotu → pondělí 3. 3.
            [2024, '2025-03-03', '2025-03-20'],
            // 1. 3. 2026 je neděle → pondělí 2. 3.
            [2025, '2026-03-02', '2026-03-20'],
            // 20. 3. 2027 je sobota → pondělí 22. 3.; zákonná lhůta zůstává.
            [2026, '2027-03-01', '2027-03-22'],
        ];
    }

    #[DataProvider('dependentActivityYears')]
    public function testDependentActivityDeadlines(
        int $year,
        string $statutory,
        string $electronic,
    ): void {
        $window = $this->policy->forYear('dpzvd6', $year);

        self::assertSame($statutory, $window->statutoryDueOn);
        self::assertSame($electronic, $window->electronicDueOn);
        // Aplikace jinou než elektronickou cestu nenabízí, hlídá se tedy ta.
        self::assertSame($electronic, $window->dueOn);
        self::assertSame(sprintf('%04d-01-01', $year + 1), $window->earliestSubmissionOn);
        self::assertFalse($window->extendable);
        self::assertSame('586/1992 Sb. § 38j odst. 5 a 7', $window->legalReference);
    }

    /**
     * Srážková daň: § 137 odst. 2 DŘ, tři měsíce, bez elektronického posunu.
     *
     * @return list<array{int,string}>
     */
    public static function withholdingYears(): array
    {
        return [
            // 1. 4. 2024 je Velikonoční pondělí → úterý 2. 4. Svátek, ne víkend.
            [2023, '2024-04-02'],
            [2024, '2025-04-01'],
            [2025, '2026-04-01'],
            // 1. 4. 2028 je sobota → pondělí 3. 4.
            [2027, '2028-04-03'],
        ];
    }

    #[DataProvider('withholdingYears')]
    public function testWithholdingDeadlines(int $year, string $due): void
    {
        $window = $this->policy->forYear('dpsvd2', $year);

        self::assertSame($due, $window->dueOn);
        self::assertSame($due, $window->statutoryDueOn);
        // Elektronické prodloužení má jen § 38j odst. 5 ZDP pro zálohovou daň;
        // obecná lhůta daňového řádu žádné nezná.
        self::assertNull($window->electronicDueOn);
        self::assertFalse($window->extendable);
        self::assertSame('280/2009 Sb. § 137 odst. 2 a 3', $window->legalReference);
    }

    /** Otisk pravidel je stabilní — jinak by se „stejná" lhůta lišila run od runu. */
    public function testRulesetFingerprintIsStableAcrossYearsAndForms(): void
    {
        $first = $this->policy->forYear('dpzvd6', 2025);
        $second = $this->policy->forYear('dpsvd2', 2031);

        self::assertSame($first->rulesetId, $second->rulesetId);
        self::assertSame($first->rulesetHash, $second->rulesetHash);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first->rulesetHash);
        self::assertSame('czech_working_days', $first->calendarBasis);
    }

    public function testUnknownFormIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy->forYear('dphdp3', 2025);
    }

    public function testYearBeforeTaxCodeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy->forYear('dpzvd6', PayrollTaxStatementDeadlinePolicy::SUPPORTED_FROM_YEAR - 1);
    }

    public function testYearAboveSupportedRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy->forYear('dpsvd2', PayrollTaxStatementDeadlinePolicy::SUPPORTED_TO_YEAR + 1);
    }
}
