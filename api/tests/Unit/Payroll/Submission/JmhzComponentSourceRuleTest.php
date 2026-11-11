<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzComponentSourceRule;
use PHPUnit\Framework\TestCase;

/**
 * Pravidlo o zařazení mzdové složky má JEDNU podobu pro zmrazení hlášení
 * i pro kontrolu před zahájením běhu. Dvě kopie by se rozešly a účetní by
 * dostala jinou odpověď podle toho, odkud se ptá.
 */
final class JmhzComponentSourceRuleTest extends TestCase
{
    public function testIncludedComponentWithoutMappingIsAFinding(): void
    {
        self::assertSame(
            'component_jmhz_mapping_missing',
            JmhzComponentSourceRule::issueCode('included', null),
        );
    }

    public function testIncludedComponentWithMappingIsFine(): void
    {
        self::assertNull(
            JmhzComponentSourceRule::issueCode('included', ['target_attribute_id' => '10329']),
        );
    }

    /**
     * Osvobozený příjem se vykazuje jako osvobozená část zúčtovaného příjmu
     * (10289), ne jako položka rozpadu mzdy. Vlastní kolonku v rozpadu nemá,
     * takže po účetní nelze chtít zařazení — vybírala by kolonku, do které
     * plnění nepatří. Úhrn se odvozuje z daňového zacházení složek.
     */
    public function testExemptIncomeNeedsNoMapping(): void
    {
        foreach (['benefit_meal', 'benefit_accommodation', 'benefit_education', 'benefit_recreation', 'benefit_health'] as $kind) {
            self::assertNull(JmhzComponentSourceRule::issueCode('included', null, 'exempt', $kind));
        }
    }

    public function testExemptIncomeWithDetailedReportingRequiresMapping(): void
    {
        foreach (['compensation', 'benefit_pension', 'benefit_care', 'risky_savings', null, 'unknown'] as $kind) {
            self::assertSame(
                'component_jmhz_mapping_missing',
                JmhzComponentSourceRule::issueCode('included', null, 'exempt', $kind),
            );
            self::assertNull(JmhzComponentSourceRule::issueCode(
                'included', ['target_attribute_id' => '10342'], 'exempt', $kind,
            ));
        }
    }

    /**
     * Zdanitelná složka zařazení potřebuje dál: bez něj by se nevědělo, do
     * které kolonky rozpadu mzdy patří.
     */
    public function testTaxableComponentStillNeedsMapping(): void
    {
        self::assertSame(
            'component_jmhz_mapping_missing',
            JmhzComponentSourceRule::issueCode('included', null, 'included'),
        );
    }

    /**
     * Odstupné: zdanitelný příjem do 10286, ale pokyny MPSV k 10328 ho z mzdy
     * za práci výslovně vylučují a náhradou mzdy (10337) také není. Chybějící
     * zařazení proto nesmí zablokovat hlášení za poslední měsíc.
     */
    public function testSeveranceNeedsNoWageBreakdownMapping(): void
    {
        self::assertNull(JmhzComponentSourceRule::issueCode('included', null, 'included', 'severance'));
        self::assertTrue(JmhzComponentSourceRule::belongsOutsideWageBreakdown('included', 'included', 'severance'));
        self::assertFalse(JmhzComponentSourceRule::belongsOutsideWageBreakdown('excluded', 'included', 'severance'));
        self::assertFalse(JmhzComponentSourceRule::belongsOutsideWageBreakdown('included', 'included', 'bonus'));
        self::assertSame(
            'component_jmhz_mapping_missing',
            JmhzComponentSourceRule::issueCode('included', null, 'included', 'bonus'),
        );
    }

    /**
     * Zdaněná (nadlimitní) cestovní náhrada a soukromé užití služebního vozu:
     * do 10286 a základu daně patří, do mzdy za práci 10328 ne (pokyny MPSV
     * cestovní náhrady jmenují výslovně). Nezdaněná část zařazení dál nemá
     * jen proto, že se do hlášení nezahrnuje.
     */
    public function testTaxedTravelExcessAndCompanyCarNeedNoWageBreakdownMapping(): void
    {
        foreach (['travel_reimbursement', 'benefit_vehicle'] as $kind) {
            self::assertNull(JmhzComponentSourceRule::issueCode('included', null, 'included', $kind), $kind);
            self::assertTrue(JmhzComponentSourceRule::belongsOutsideWageBreakdown('included', 'included', $kind), $kind);
            self::assertSame(
                'component_jmhz_manual_review',
                JmhzComponentSourceRule::issueCode('manual_review', null, 'manual_review', $kind),
                $kind,
            );
        }
        self::assertSame(
            'component_jmhz_mapping_missing',
            JmhzComponentSourceRule::issueCode('included', null, 'included', 'non_cash'),
        );
    }

    /**
     * Vyřazená složka nemá co mapovat — chybějící zařazení u ní není nález,
     * je to správný stav.
     */
    public function testExcludedComponentIsNeverAFinding(): void
    {
        self::assertNull(JmhzComponentSourceRule::issueCode('excluded', null));
    }

    public function testManualReviewAsksForConfirmation(): void
    {
        self::assertSame(
            'component_jmhz_manual_review',
            JmhzComponentSourceRule::issueCode('manual_review', null),
        );
    }

    public function testUnknownTreatmentIsInvalidSetup(): void
    {
        foreach ([null, '', 'nesmysl', 42] as $treatment) {
            self::assertSame(
                'component_jmhz_treatment_invalid',
                JmhzComponentSourceRule::issueCode($treatment, null),
            );
        }
    }
}
