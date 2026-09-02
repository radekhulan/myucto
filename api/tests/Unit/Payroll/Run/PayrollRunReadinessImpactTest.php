<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

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
     * Co vstupuje do zmrazeného snímku, se po zamknutí opraví jen novou revizí.
     * Nezastavuje to, ale účetní o tom následku musí vědět předem.
     */
    public function testFrozenSnapshotInputsWarnAboutCorrectionRevision(): void
    {
        foreach (['draft_inputs_present', 'time_month_not_approved'] as $code) {
            $described = PayrollRunReadinessImpact::describe($code);
            self::assertSame(
                PayrollRunReadinessImpact::IMPACT_REVISION,
                $described['impact'],
                $code,
            );
            self::assertSame(
                PayrollRunReadinessImpact::SCOPE_MONTHLY,
                $described['scope'],
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
}
