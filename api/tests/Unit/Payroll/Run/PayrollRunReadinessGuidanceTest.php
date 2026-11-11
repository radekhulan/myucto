<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use MyInvoice\Service\Payroll\Run\PayrollRunIssueGuidance;
use MyInvoice\Service\Payroll\Run\PayrollRunJmhzReadinessProbe;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunValidation;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzBlockerRemediationPath;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Blocker;
use PHPUnit\Framework\TestCase;

final class PayrollRunReadinessGuidanceTest extends TestCase
{
    public function testGroupedFindingsKeepEachAffectedRelationshipAndDestination(): void
    {
        $first = new PayrollRunValidation('warning', 'time_month_missing', 'employment', 11, 'Vztah první nemá docházku.', '/payroll/time?employment=11');
        $second = new PayrollRunValidation('warning', 'time_month_missing', 'employment', 22, 'Vztah druhý nemá docházku.', '/payroll/time?employment=22');
        $findings = self::invoke(PayrollRunReadinessService::class, 'groupValidations', [[$first, $second]]);
        self::assertCount(1, $findings);
        self::assertNull($findings[0]['remediation_path']);
        self::assertSame($first->remediationPath, $findings[0]['entities'][0]['remediation_path']);
        self::assertSame($second->remediationPath, $findings[0]['entities'][1]['remediation_path']);
        self::assertSame($second->message, $findings[0]['entities'][1]['message']);
        self::assertNotSame($first->message, $findings[0]['message']);
    }

    /** Q8-32: u každého záznamu je jméno, ne jen „Otevřít místo k opravě". */
    public function testGroupedEntitiesCarryTheEmploymentLabel(): void
    {
        $first = new PayrollRunValidation('warning', 'time_month_missing', 'employment', 11, 'Docházka chybí.', '/payroll/time?employment=11');
        $second = new PayrollRunValidation('warning', 'time_month_missing', 'employment', 22, 'Docházka chybí.', '/payroll/time?employment=22');
        $findings = self::invoke(PayrollRunReadinessService::class, 'groupValidations', [
            [$first, $second],
            [11 => 'Syntetická osoba (HPP-1)'],
        ]);
        self::assertSame('Syntetická osoba (HPP-1)', $findings[0]['entities'][0]['label']);
        self::assertNull($findings[0]['entities'][1]['label']);
    }

    public function testSingleFindingKeepsItsDirectDestination(): void
    {
        $finding = new PayrollRunValidation('warning', 'time_month_missing', 'employment', 11, 'Docházka chybí.', '/payroll/time?employment=11');
        $groups = self::invoke(PayrollRunReadinessService::class, 'groupValidations', [[$finding]]);
        self::assertSame($finding->remediationPath, $groups[0]['remediation_path']);
        self::assertSame($finding->message, $groups[0]['message']);
    }

    public function testIdentityLinksOpenTheActualMissingField(): void
    {
        self::assertSame(
            '/payroll/people?employment=42&panel=jmhz_identity&field=jmhz.employment_external_identifier',
            self::invoke(PayrollRunJmhzReadinessProbe::class, 'remediationPath', ['jmhz_identity_id_ppv_missing', 42]),
        );
        self::assertSame(
            '/payroll/people?employment=42&panel=jmhz_identity&field=jmhz.person_external_identifier',
            self::invoke(PayrollRunJmhzReadinessProbe::class, 'remediationPath', ['jmhz_identity_oic_missing', 42]),
        );
    }

    public function testUnknownJmhzIssueDoesNotExposeCodeOrPretendToKnowInput(): void
    {
        $code = 'jmhz_future_internal_failure';
        $message = self::invoke(PayrollRunJmhzReadinessProbe::class, 'message', [$code, ['Testovací vztah'], 1]);
        self::assertStringNotContainsString($code, $message);
        self::assertStringContainsString('JMHZ', $message);
        self::assertSame('/payroll/submissions/jmhz', self::invoke(PayrollRunJmhzReadinessProbe::class, 'remediationPath', [$code]));
    }

    /**
     * Kód, který katalog JMHZ zná, nevede na obecnou stránku podání, ale na
     * místo opravy, a to na zpracovávaný měsíc.
     */
    public function testCatalogKnownJmhzIssueLinksToItsFixWithThePeriod(): void
    {
        self::assertSame(
            '/payroll/absences?employment=22&tab=absences&period=2026-08',
            self::invoke(PayrollRunJmhzReadinessProbe::class, 'remediationPath', [
                'jmhz_xml_eldp_deducted_days_sum_mismatch', 22, '2026-08-01', 'employment',
            ]),
        );
        self::assertSame(
            '/payroll/time?employment=22&period=2026-08',
            self::invoke(PayrollRunJmhzReadinessProbe::class, 'remediationPath', [
                'jmhz_eldp_interval_invalid', 22, '2026-08-01', 'employment',
            ]),
        );
    }

    /** Odkazy ze zmrazení hlášení nesou vykazované období, ne dnešní měsíc. */
    public function testFreezeBlockerLinksCarryTheReportedPeriod(): void
    {
        $path = static fn (string $code, string $type, ?int $id): ?string => JmhzBlockerRemediationPath::forBlocker(
            new JmhzScenario1Blocker($code, $type, $id),
            '2026-08-01',
        );
        self::assertSame('/payroll/runs?period=2026-08', $path('eldp_assessment_base_missing', 'run', null));
        self::assertSame(
            '/payroll/absences?employment=22&tab=averages&year=2026&quarter=3',
            $path('jmhz_average_hourly_earning_missing', 'employment', 22),
        );
        self::assertSame('/payroll/time?employment=22&period=2026-08', $path('jmhz_eldp_interval_invalid', 'employment', 22));
        // Bez známého období zůstává odkaz po staru.
        self::assertSame('/payroll/runs', JmhzBlockerRemediationPath::forBlocker(
            new JmhzScenario1Blocker('eldp_assessment_base_missing', 'run', null),
        ));
    }

    /** Nález zařazení složky vede do číselníku, ne na výchozí záložku Mzdové vstupy. */
    public function testComponentFindingsOpenTheCatalogTab(): void
    {
        self::assertSame(
            '/payroll/components?tab=catalog',
            PayrollRunIssueGuidance::describe('component_treatment_unverified', 7, 9)['remediation_path'],
        );
        self::assertSame(
            '/payroll/components?tab=risky_savings&person=11&employment=22',
            self::invoke(PayrollRunSnapshotBuilder::class, 'riskySavingsPath', [11, 22]),
        );
    }

    public function testDiscountWarningLinksToAnExistingEmployeePage(): void
    {
        $validations = self::invoke(PayrollRunSnapshotBuilder::class, 'discountValidations', [
            ['social_part_time_discount_reason' => 'age_55'], null, 22, 11, '2026-02-01',
        ]);
        $transition = array_values(array_filter($validations, static fn ($item) => $item->code === 'part_time_discount_transitional_window'));
        self::assertCount(1, $transition);
        self::assertSame('/payroll/people?person=11&employment=22&panel=employment_terms&field=social_part_time_discount_reason', $transition[0]->remediationPath);
    }

    /** OZUSPOJ-formularOzuspoj-6: věková hranice důvodu slevy proti datu narození. */
    public function testAgeBoundDiscountReasonWarnsWhenTheBirthDateContradictsOrIsMissing(): void
    {
        $codes = static fn (array $row): array => array_map(
            static fn ($item) => $item->code,
            self::invoke(PayrollRunSnapshotBuilder::class, 'discountValidations', [
                $row + ['social_part_time_discount_reason' => 'age_55_plus', 'start_date' => '2020-01-01', 'actual_start_date' => null, 'end_date' => null],
                null, 22, 11, '2026-06-01',
            ]),
        );

        self::assertContains('part_time_discount_age_condition', $codes(['employee_birth_date' => '1990-01-01']));
        self::assertContains('part_time_discount_age_condition', $codes(['employee_birth_date' => null]));
        self::assertNotContains('part_time_discount_age_condition', $codes(['employee_birth_date' => '1960-01-01']));
    }

    /** OZUSPOJ-formularOzuspoj-5: § 23d odst. 2, poučení zaměstnance před prvním uplatněním slevy. */
    public function testAcceptedIntentWithoutEmployeeInformationWarns(): void
    {
        $intent = ['status' => 'accepted', 'intent_from' => '2026-05-01', 'intent_to' => null, 'accepted_on' => '2026-04-20', 'discount_reason' => 'age_55'];
        $validations = self::invoke(PayrollRunSnapshotBuilder::class, 'discountValidations', [
            ['social_part_time_discount_reason' => 'age_55'], $intent, 22, 11, '2026-06-01',
            ['intent_from' => '2026-05-01', 'employee_informed_on' => null, 'predecessor_source' => null],
        ]);
        $codes = array_map(static fn ($item) => $item->code, $validations);

        self::assertContains('part_time_discount_employee_not_informed', $codes);
    }

    public function testEmployeeInformedAfterTheFirstMonthWarnsAndInTimeDoesNot(): void
    {
        $intent = ['status' => 'accepted', 'intent_from' => '2026-05-01', 'intent_to' => null, 'accepted_on' => '2026-04-20', 'discount_reason' => 'age_55'];
        $run = static fn (?string $informedOn, ?string $predecessor = null): array => array_map(
            static fn ($item) => $item->code,
            self::invoke(PayrollRunSnapshotBuilder::class, 'discountValidations', [
                ['social_part_time_discount_reason' => 'age_55'], $intent, 22, 11, '2026-06-01',
                ['intent_from' => '2026-05-01', 'employee_informed_on' => $informedOn, 'predecessor_source' => $predecessor],
            ]),
        );

        self::assertContains('part_time_discount_employee_not_informed', $run('2026-06-02'));
        self::assertNotContains('part_time_discount_employee_not_informed', $run('2026-04-30'));
        self::assertNotContains('part_time_discount_employee_not_informed', $run('2026-05-31'));
        self::assertNotContains('part_time_discount_employee_not_informed', $run(null, 'previous_program'));
    }

    /**
     * Měsíce zpracované v předchozím programu patří do počátečních stavů, ne do
     * mzdových běhů. Kontrola je proto musí odmítnout dřív, než z nich vyrobí
     * dvě stě nálezů o chybějící docházce a vstupech.
     */
    public function testPeriodBeforeTheFirstPayrollMonthIsRefusedOutright(): void
    {
        // Hranici drží PayrollHistoricalPeriodService — tentýž předěl odděluje
        // historii i v přehledu docházky a ve výpisu mzdových vstupů, takže
        // kontrola před během si ji nesmí počítat po svém.
        $precedes = PayrollHistoricalPeriodService::precedesStart(...);

        self::assertTrue($precedes('2026-11', '2026-06-01'));
        self::assertTrue($precedes('2026-06-01', '2026-05-01'));
        // Měsíc, kterým vedení mezd začíná, se počítá normálně.
        self::assertFalse($precedes('2026-06', '2026-06-01'));
        self::assertFalse($precedes('2026-06', '2026-07-01'));
        // Bez nastaveného začátku se nic neodmítá — modul se tak choval vždy.
        self::assertFalse($precedes(null, '2026-06-01'));
        self::assertFalse($precedes('', '2026-06-01'));
    }

    private static function invoke(string $class, string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionClass($class);
        return $reflection->getMethod($method)->invokeArgs($reflection->newInstanceWithoutConstructor(), $arguments);
    }
}
