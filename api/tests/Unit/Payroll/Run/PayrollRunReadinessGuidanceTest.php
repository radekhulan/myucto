<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollRunJmhzReadinessProbe;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunValidation;
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

    public function testDiscountWarningLinksToAnExistingEmployeePage(): void
    {
        $validations = self::invoke(PayrollRunSnapshotBuilder::class, 'discountValidations', [
            ['social_part_time_discount_reason' => 'age_55'], null, 22, 11, '2026-02-01',
        ]);
        $transition = array_values(array_filter($validations, static fn ($item) => $item->code === 'part_time_discount_transitional_window'));
        self::assertCount(1, $transition);
        self::assertSame('/payroll/people?person=11&employment=22&panel=employment_terms&field=social_part_time_discount_reason', $transition[0]->remediationPath);
    }

    private static function invoke(string $class, string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionClass($class);
        return $reflection->getMethod($method)->invokeArgs($reflection->newInstanceWithoutConstructor(), $arguments);
    }
}
