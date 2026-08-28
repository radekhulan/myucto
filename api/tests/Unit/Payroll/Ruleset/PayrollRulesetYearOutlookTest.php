<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Ruleset;

use DateTimeImmutable;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetYearOutlook;
use MyInvoice\Tests\Fixtures\Payroll\ShiftedYearPayrollRulesetFixture;
use PHPUnit\Framework\TestCase;

/**
 * Chybějící sada na příští rok je jediná porucha, kterou fail-closed brána
 * ohlásí až v okamžiku, kdy se s ní nedá nic dělat. Tenhle test drží, že se
 * o ní mluví DŘÍV.
 */
final class PayrollRulesetYearOutlookTest extends TestCase
{
    public function testMissingNextYearIsAlreadyWarnedAboutInSpring(): void
    {
        $outlook = PayrollRulesetYearOutlook::forProvider(
            CzechPayrollRulesets2026::provider(),
            new DateTimeImmutable('2026-03-15'),
        );

        self::assertCount(PayrollRulesetYearOutlook::HORIZON_YEARS, $outlook);
        self::assertSame(2027, $outlook[0]['year']);
        self::assertFalse($outlook[0]['covered']);
        self::assertSame('warning', $outlook[0]['severity']);
        self::assertSame('year_ruleset_missing', $outlook[0]['code']);
        self::assertSame(
            ['compensation_averages', 'employment_thresholds', 'health_insurance', 'income_tax', 'social_insurance'],
            $outlook[0]['missing_domains'],
        );
        self::assertStringContainsString('31. 12. 2026', $outlook[0]['message']);
    }

    /**
     * Od 1. října je průměrná mzda pro příští rok vyhlášená, takže „ještě to
     * nejde spočítat" přestává být omluva a varování se přitvrdí.
     */
    public function testFromOctoberTheMissingNextYearIsCritical(): void
    {
        $before = PayrollRulesetYearOutlook::forProvider(
            CzechPayrollRulesets2026::provider(),
            new DateTimeImmutable('2026-09-30 23:59:59'),
        );
        $after = PayrollRulesetYearOutlook::forProvider(
            CzechPayrollRulesets2026::provider(),
            new DateTimeImmutable('2026-10-01 00:00:00'),
        );

        self::assertSame('warning', $before[0]['severity']);
        self::assertSame('critical', $after[0]['severity']);
        self::assertStringContainsString('JEŠTĚ LETOS', $after[0]['message']);
        self::assertSame('critical', PayrollRulesetYearOutlook::worstSeverity(
            CzechPayrollRulesets2026::provider(),
            new DateTimeImmutable('2026-10-01'),
        ));
    }

    /**
     * Přespříští rok chybí legitimně — hodnoty pro něj ještě nikdo nevyhlásil.
     * Kdyby se hlásil stejně naléhavě jako příští rok, přestane si ho kdokoli
     * všímat i tehdy, když bude naléhavý doopravdy.
     */
    public function testTheYearAfterNextIsOnlyInformational(): void
    {
        $outlook = PayrollRulesetYearOutlook::forProvider(
            CzechPayrollRulesets2026::provider(),
            new DateTimeImmutable('2026-11-20'),
        );

        self::assertSame(2028, $outlook[1]['year']);
        self::assertSame('info', $outlook[1]['severity']);
        self::assertStringContainsString('je to informace, ne úkol', $outlook[1]['message']);
    }

    public function testCoveredNextYearStopsWarning(): void
    {
        $rulesets = ShiftedYearPayrollRulesetFixture::provider(2027);
        $outlook = PayrollRulesetYearOutlook::forProvider(
            $rulesets,
            new DateTimeImmutable('2026-12-20'),
        );

        self::assertTrue($outlook[0]['covered']);
        self::assertSame('ok', $outlook[0]['severity']);
        self::assertSame([], $outlook[0]['missing_domains']);
        self::assertSame('info', PayrollRulesetYearOutlook::worstSeverity(
            $rulesets,
            new DateTimeImmutable('2026-12-20'),
        ));
    }
}
