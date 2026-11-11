<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollRunIssueGuidance;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessImpact;
use PHPUnit\Framework\TestCase;

/**
 * Zařazení nálezů se nesmí řídit tím, jak vážně znějí.
 *
 * Uživatel na tom strávil tři dny: aplikace ho zastavovala na věcech, které
 * zastavovat nemusely. Test proto hlídá tu jedinou otázku, podle které se
 * rozhoduje — dá se to opravit potom?
 */
final class PayrollRunReadinessImpactTest extends TestCase
{
    public function testMissingEmployerPolicyIsTheOnlyKindThatStops(): void
    {
        $policy = PayrollRunReadinessImpact::describe('employer_policy_missing');

        self::assertSame(PayrollRunReadinessImpact::IMPACT_BLOCKING, $policy['impact']);
        self::assertSame('blocker', $policy['severity']);
        self::assertSame(PayrollRunReadinessImpact::SCOPE_SETUP, $policy['scope']);
    }

    /**
     * Měsíc před prvním mzdovým obdobím firmy zastavuje stejně jako chybějící
     * politika: běh za něj nejde ani založit, takže cokoli mírnějšího by účetní
     * pustilo do slepé uličky.
     */
    public function testPeriodBeforeModuleStartStopsTheRun(): void
    {
        $described = PayrollRunReadinessImpact::describe('period_before_module_start');

        self::assertSame(PayrollRunReadinessImpact::IMPACT_BLOCKING, $described['impact']);
        self::assertSame('blocker', $described['severity']);
        self::assertSame(PayrollRunReadinessImpact::SCOPE_SETUP, $described['scope']);
    }

    /**
     * Chybějící identifikátory od ČSSZ NEJSOU blokátor. Přiděluje je ČSSZ, ne
     * účetní, doplní se kdykoli před podáním a s mzdovým výpočtem nemají nic
     * společného. Navíc pro ně existuje legální alternativa: `identifikaceType`
     * v XSD JMHZ je `xs:choice` a zaměstnance bez OIČ lze vykázat jmennou
     * větví.
     */
    public function testCsszIdentifiersNeverBlockTheRun(): void
    {
        foreach ([
            'jmhz_identity_oic_missing',
            'jmhz_identity_id_ppv_missing',
            'jmhz_identity_incomplete',
            'jmhz_identity_whatever_comes_later',
        ] as $code) {
            $described = PayrollRunReadinessImpact::describe($code);
            self::assertSame(
                PayrollRunReadinessImpact::IMPACT_ANYTIME,
                $described['impact'],
                $code,
            );
            self::assertSame('info', $described['severity'], $code);
        }
    }

    /**
     * Neschválená docházka a rozpracované vstupy běh nezastaví, ale schválit
     * ho nepůjde — v běhu jsou blokující validace. Předběžná kontrola je proto
     * musí ukázat jako blokaci schválení, ne jako žluté varování.
     */
    public function testUnapprovedSourcesBlockApprovalNotStart(): void
    {
        foreach (['draft_inputs_present', 'time_month_not_approved', 'time_month_missing', 'employment_without_inputs'] as $code) {
            $described = PayrollRunReadinessImpact::describe($code);
            self::assertSame(
                PayrollRunReadinessImpact::IMPACT_APPROVAL,
                $described['impact'],
                $code,
            );
            self::assertSame('blocker', $described['severity'], $code);
            self::assertSame(
                PayrollRunReadinessImpact::SCOPE_MONTHLY,
                $described['scope'],
                $code,
            );
        }
    }

    /** Co by po zahájení blokovalo schválení, nesmí se před ním tvářit jako varování. */
    public function testSnapshotBlockerIsNeverWeakerThanApprovalBlock(): void
    {
        $described = PayrollRunReadinessImpact::describeValidation('recurring_component_without_input', 'blocker');
        self::assertSame(PayrollRunReadinessImpact::IMPACT_APPROVAL, $described['impact']);
        self::assertSame('blocker', $described['severity']);

        $warning = PayrollRunReadinessImpact::describeValidation('recurring_component_without_input', 'warning');
        self::assertSame(PayrollRunReadinessImpact::IMPACT_REVISION, $warning['impact']);

        $stop = PayrollRunReadinessImpact::describeValidation('employer_policy_missing', 'blocker');
        self::assertSame(PayrollRunReadinessImpact::IMPACT_BLOCKING, $stop['impact']);
    }

    /**
     * Blokace hlášení čtená ze schváleného běhu nejde odstranit opravou údaje:
     * hláška musí říct, že je potřeba opravná revize. Údaj čtený až při
     * přípravě hlášení revizi nestojí a hláška ji nesmí požadovat zbytečně.
     */
    public function testJmhzBlockerFromFrozenRunNeedsRevisionAndLiveSourceDoesNot(): void
    {
        foreach ([
            ['jmhz_office_variable_symbol_invalid', 'office', []],
            ['jmhz_scenario1_child_credit_caregiver_unknown', 'person', []],
            ['jmhz_workplace_codebooks_unverified', 'employment', []],
            ['jmhz_average_hourly_earning_unverified', 'employment', []],
            ['jmhz_work_month_not_approved', 'employment', []],
            // Hlášení 2027: adresa pracoviště je v podmínkách vztahu, den podpisu v zákonné evidenci.
            ['jmhz_2027_source_missing', 'employment', ['10550']],
            ['jmhz_2027_source_missing', 'person', ['10575']],
        ] as [$code, $entityType, $attributeIds]) {
            self::assertTrue(
                PayrollRunReadinessImpact::jmhzBlockerNeedsRevision($code, $entityType, $attributeIds),
                $code,
            );
        }
        foreach ([
            ['component_jmhz_mapping_missing', 'component', []],
            ['jmhz_identity_oic_missing', 'employment', []],
            ['jmhz_employer_annual_collective_agreement_missing', 'supplier', []],
            ['jmhz_annual_request_status_unresolved', 'employee', []],
            ['jmhz_scenario1_child_identity_incomplete', 'person', []],
            ['jmhz_ordinary_evidence_missing', 'employment', []],
            ['jmhz_workplace_codebooks_not_published', 'employment', []],
            ['jmhz_2027_source_missing', 'revision', ['10214']],
            // Kód, který nikdo nezařadil: o revizi se nic netvrdí.
            ['future_rule', 'employment', []],
        ] as [$code, $entityType, $attributeIds]) {
            self::assertFalse(
                PayrollRunReadinessImpact::jmhzBlockerNeedsRevision($code, $entityType, $attributeIds),
                $code,
            );
        }
    }

    /** Totéž zařazení platí pro předpověď v kontrole před během. */
    public function testPredictedJmhzFindingsFromFrozenRunAreRevisionImpact(): void
    {
        foreach ([
            'jmhz_office_variable_symbol_invalid',
            'jmhz_scenario1_child_credit_caregiver_unknown',
            'jmhz_scenario1_child_credit_caregiver_inconsistent',
        ] as $code) {
            self::assertSame(
                PayrollRunReadinessImpact::IMPACT_REVISION,
                PayrollRunReadinessImpact::describe($code)['impact'],
                $code,
            );
        }
    }

    /**
     * Neznámý kód nesmí nikdy zastavit. Falešná závora je horší než falešné
     * varování — koho aplikace pustí dál, ten si problém opraví.
     */
    public function testUnknownCodeIsOnlyInformation(): void
    {
        $described = PayrollRunReadinessImpact::describe('nekdo_zapomnel_zaradit');

        self::assertSame(PayrollRunReadinessImpact::IMPACT_ANYTIME, $described['impact']);
        self::assertSame('info', $described['severity']);
    }

    /** Zařazení složek do JMHZ je konfigurace firmy, ne měsíční práce. */
    public function testComponentMappingIsSetupNotMonthlyWork(): void
    {
        $described = PayrollRunReadinessImpact::describe('component_jmhz_mapping_missing');

        self::assertSame(PayrollRunReadinessImpact::SCOPE_SETUP, $described['scope']);
        self::assertSame(PayrollRunReadinessImpact::IMPACT_ANYTIME, $described['impact']);
    }

    /** Neověřitelný roční limit DPP z ručního podkladu je varování s cestou k hodinám. */
    public function testDppAnnualHoursManualReviewIsClassifiedWarning(): void
    {
        $described = PayrollRunReadinessImpact::describe('dpp_annual_hours_manual_review_required');

        self::assertSame('warning', $described['severity']);
        self::assertSame(PayrollRunReadinessImpact::SCOPE_MONTHLY, $described['scope']);
        $guidance = PayrollRunIssueGuidance::describe('dpp_annual_hours_manual_review_required');
        self::assertSame('/payroll/time', $guidance['remediation_path']);
        self::assertStringContainsString('§ 75', $guidance['message']);
    }
}
