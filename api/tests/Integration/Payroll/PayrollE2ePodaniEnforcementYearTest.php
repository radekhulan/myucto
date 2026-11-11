<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\EnforcementRunFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přechod roku a exekuční srážky: nezabavitelné částky se berou k 1. lednu
 * roku VÝPLATY (§ 4 nař. vlády č. 595/2006 Sb.), takže prosincová mzda
 * vyplacená v lednu 2027 potřebuje sadu 2027, která zatím vyhlášená není.
 */
#[Group('integration')]
final class PayrollE2ePodaniEnforcementYearTest extends TestCase
{
    use EnforcementRunFixtureTrait;

    protected function setUp(): void
    {
        $this->bootEnforcementRun(3_500_000);
    }

    protected function tearDown(): void
    {
        $this->tearDownEnforcementRun();
    }

    /**
     * Prosinec 2026 vyplacený 12. 1. 2027 u zaměstnance s exekuční srážkou:
     * sada exekučních srážek 2027 chybí (normativní nájemné pro rok 2027
     * nevyhlášeno), běh se zastaví blokací, která říká proč a kam
     * (Legislativní pravidla mezd), a nikdy nepočítá podle 2026.
     */
    public function testDecemberPaidInJanuaryStopsOnMissingEnforcementRuleset2027(): void
    {
        $this->seedDecemberWage();
        $this->seedRunClaim($this->seedRunCase('withhold_and_hold'));
        $this->seedRunMonthEvidence('2026-12-01');

        $run = $this->calculateEnforcementRun('2026-12-01', '2027-01-12');

        self::assertSame('manual_review', $run['enforcement']['status']);
        self::assertContains('payment_date_outside_ruleset_2026', $run['enforcement']['issues']);
        self::assertSame(0, $run['enforcement']['total_withheld_minor_units']);
        $ruleset = array_values(array_filter(
            $run['validations'],
            static fn (array $validation): bool => $validation['remediation_path'] === '/payroll/rulesets',
        ));
        self::assertCount(1, $ruleset, CanonicalJson::encode($run['validations']));
        self::assertSame('blocker', $ruleset[0]['severity']);
        self::assertStringContainsString('Pro rok výplaty chybí pravidla exekučních srážek', $ruleset[0]['message']);
        self::assertStringContainsString('Datum výplaty kvůli tomu neměňte', $ruleset[0]['message']);
    }

    /**
     * Zaměstnanec, kterému se nic nesráží, sadu srážek roku výplaty
     * nepotřebuje: prosincová mzda vyplacená v lednu 2027 se spočítá a běh
     * chybějící sada 2027 nezastaví.
     */
    public function testDecemberPaidInJanuaryWithoutDeductionsIsNotStopped(): void
    {
        $this->seedDecemberWage();

        $run = $this->calculateEnforcementRun('2026-12-01', '2027-01-12');

        self::assertSame('calculated', $run['person']['statutory']['status']);
        self::assertSame([], $run['enforcement']['issues']);
        self::assertSame([], array_values(array_filter(
            $run['validations'],
            static fn (array $validation): bool => $validation['remediation_path'] === '/payroll/rulesets',
        )), CanonicalJson::encode($run['validations']));
    }

    /** Tentýž měsíc vyplacený ještě v prosinci 2026 sadu 2026 má. */
    public function testDecemberPaidInDecemberUsesEnforcementRuleset2026(): void
    {
        $this->seedDecemberWage();
        $this->seedRunClaim($this->seedRunCase('withhold_and_hold'));
        $this->seedRunMonthEvidence('2026-12-01');

        $run = $this->calculateEnforcementRun('2026-12-01', '2026-12-30');

        self::assertNotContains('payment_date_outside_ruleset_2026', $run['enforcement']['issues']);
        self::assertSame('cz-payroll-2026.enforcement-deductions.v1', $run['enforcement']['ruleset_id']);
        self::assertSame([], array_values(array_filter(
            $run['validations'],
            static fn (array $validation): bool => $validation['remediation_path'] === '/payroll/rulesets',
        )));
    }

    /** Výchozí vstup přípravku je za červen; prosincový běh potřebuje vlastní. */
    private function seedDecemberWage(): void
    {
        $this->seedRunInput($this->componentIds['MZDA_MESICNI'], 3_500_000, '2026-12-01');
    }
}
