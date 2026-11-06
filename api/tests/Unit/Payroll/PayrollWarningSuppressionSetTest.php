<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunValidation;
use MyInvoice\Service\Payroll\Run\PayrollWarningSuppressionCatalog;
use MyInvoice\Service\Payroll\Run\PayrollWarningSuppressionSet;
use PHPUnit\Framework\TestCase;

/**
 * Pravidla trvalého skrytí varování nad kontrolami běhu (bez databáze).
 */
final class PayrollWarningSuppressionSetTest extends TestCase
{
    public function testWithoutSuppressionsEverythingStaysUnchanged(): void
    {
        $rows = [
            self::row(1, 'blocker', 'time_month_not_approved', 'employment', 10),
            self::row(2, 'warning', 'time_month_missing', 'employment', 11),
            self::summaryRow(3, [100, 101]),
        ];

        $result = PayrollWarningSuppressionSet::empty()->filterRows($rows);

        self::assertSame(0, $result['hidden_count']);
        self::assertCount(3, $result['visible']);
        self::assertSame($rows[2]['message'], $result['visible'][2]['message']);
        self::assertFalse($result['visible'][0]['hideable']);
        self::assertTrue($result['visible'][1]['hideable']);
        self::assertSame('employment', $result['visible'][1]['subject_type']);
        self::assertSame([11], $result['visible'][1]['subject_ids']);
        self::assertSame([100, 101], $result['visible'][2]['subject_ids']);
    }

    public function testBlockersAndOverrideWarningsAreNeverHidden(): void
    {
        $set = PayrollWarningSuppressionSet::fromRows([
            ['code' => 'time_month_missing', 'subject_type' => 'supplier', 'subject_id' => 0],
            ['code' => 'employment_social_registration_missing', 'subject_type' => 'supplier', 'subject_id' => 0],
        ]);
        $rows = [
            self::row(1, 'blocker', 'time_month_missing', 'employment', 10),
            self::row(2, 'warning', 'employment_social_registration_missing', 'employment', 10, true),
            self::row(3, 'warning', 'time_month_missing', 'employment', 11),
        ];

        $result = $set->filterRows($rows);

        self::assertSame(1, $result['hidden_count']);
        self::assertSame([1, 2], array_column($result['visible'], 'id'));
    }

    public function testPersonScopeHidesOnlyThatEmployment(): void
    {
        $set = PayrollWarningSuppressionSet::fromRows([
            ['code' => 'time_month_missing', 'subject_type' => 'employment', 'subject_id' => 11],
        ]);

        $result = $set->filterRows([
            self::row(1, 'warning', 'time_month_missing', 'employment', 10),
            self::row(2, 'warning', 'time_month_missing', 'employment', 11),
        ]);

        self::assertSame(1, $result['hidden_count']);
        self::assertSame([1], array_column($result['visible'], 'id'));
    }

    public function testSubjectOfWrongKindIsIgnored(): void
    {
        $set = PayrollWarningSuppressionSet::fromRows([
            ['code' => 'time_month_missing', 'subject_type' => 'employee', 'subject_id' => 11],
        ]);

        $result = $set->filterRows([self::row(1, 'warning', 'time_month_missing', 'employment', 11)]);

        self::assertSame(0, $result['hidden_count']);
    }

    public function testSummaryIsNarrowedThenHiddenWhenAllPeopleAreHidden(): void
    {
        $partial = PayrollWarningSuppressionSet::fromRows([
            ['code' => 'tax_declaration_not_signed_summary', 'subject_type' => 'employee', 'subject_id' => 100],
        ]);
        $result = $partial->filterRows([self::summaryRow(1, [100, 101, 102])]);
        self::assertSame(0, $result['hidden_count']);
        self::assertSame([101, 102], $result['visible'][0]['subject_ids']);
        self::assertSame(PayrollRunSnapshotBuilder::unsignedDeclarationsMessage(2), $result['visible'][0]['message']);

        $all = PayrollWarningSuppressionSet::fromRows([
            ['code' => 'tax_declaration_not_signed_summary', 'subject_type' => 'employee', 'subject_id' => 100],
            ['code' => 'tax_declaration_not_signed_summary', 'subject_type' => 'employee', 'subject_id' => 101],
        ]);
        self::assertSame(1, $all->filterRows([self::summaryRow(1, [100, 101])])['hidden_count']);
    }

    /**
     * Souhrn uložený před zavedením subjektů id osob nemá: po osobách ho
     * skrýt nejde, jen celým typem.
     */
    public function testLegacySummaryWithoutSubjectsHidesOnlyByType(): void
    {
        $people = PayrollWarningSuppressionSet::fromRows([
            ['code' => 'tax_declaration_not_signed_summary', 'subject_type' => 'employee', 'subject_id' => 100],
        ]);
        self::assertSame(0, $people->filterRows([self::summaryRow(1, [])])['hidden_count']);

        $type = PayrollWarningSuppressionSet::fromRows([
            ['code' => 'tax_declaration_not_signed_summary', 'subject_type' => 'supplier', 'subject_id' => 0],
        ]);
        self::assertSame(1, $type->filterRows([self::summaryRow(1, [])])['hidden_count']);
    }

    public function testSnapshotValidationsAreFilteredTheSameWay(): void
    {
        $set = PayrollWarningSuppressionSet::fromRows([
            ['code' => 'tax_declaration_not_signed_summary', 'subject_type' => 'employee', 'subject_id' => 100],
            ['code' => 'employment_without_inputs', 'subject_type' => 'supplier', 'subject_id' => 0],
        ]);

        $result = $set->filterValidations([
            new PayrollRunValidation('warning', 'employment_without_inputs', 'employment', 5, 'X: bez vstupu.'),
            new PayrollRunValidation('blocker', 'draft_inputs_present', 'employment', 5, 'X: koncept.'),
            new PayrollRunValidation(
                'warning',
                'tax_declaration_not_signed_summary',
                'run',
                null,
                PayrollRunSnapshotBuilder::unsignedDeclarationsMessage(2),
                '/payroll/people',
                subjectIds: [100, 101],
            ),
        ]);

        self::assertCount(2, $result);
        self::assertSame('draft_inputs_present', $result[0]->code);
        self::assertSame([101], $result[1]->subjectIds);
        self::assertSame(PayrollRunSnapshotBuilder::unsignedDeclarationsMessage(1), $result[1]->message);
    }

    public function testCatalogListsOnlyWarningsThatCanBeOk(): void
    {
        self::assertTrue(PayrollWarningSuppressionCatalog::isHideable('tax_declaration_not_signed_summary', 'warning', false));
        self::assertFalse(PayrollWarningSuppressionCatalog::isHideable('tax_declaration_not_signed_summary', 'blocker', false));
        self::assertFalse(PayrollWarningSuppressionCatalog::isHideable('tax_declaration_not_signed_summary', 'warning', true));
        foreach (['dpp_annual_hours_exceeded', 'dpc_weekly_average_exceeded', 'enforcement_case_received', 'part_time_discount_intent_missing'] as $code) {
            self::assertFalse(PayrollWarningSuppressionCatalog::isHideableCode($code), $code);
        }
        self::assertTrue(PayrollWarningSuppressionCatalog::isHideable('dpp_annual_hours_approaching', 'warning', false));
        self::assertSame('employment', PayrollWarningSuppressionCatalog::subjectType('dpp_annual_hours_approaching'));
    }

    /** @return array<string,mixed> */
    private static function row(
        int $id,
        string $severity,
        string $code,
        string $entityType,
        ?int $entityId,
        bool $requiresOverride = false,
    ): array {
        return [
            'id' => $id,
            'severity' => $severity,
            'code' => $code,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'message' => 'Syntetická osoba: zpráva.',
            'requires_override' => $requiresOverride,
            'subject_ids' => [],
        ];
    }

    /**
     * @param list<int> $subjects
     * @return array<string,mixed>
     */
    private static function summaryRow(int $id, array $subjects): array
    {
        return [
            ...self::row($id, 'warning', 'tax_declaration_not_signed_summary', 'run', null),
            'message' => PayrollRunSnapshotBuilder::unsignedDeclarationsMessage(count($subjects) ?: 3),
            'subject_ids' => $subjects,
        ];
    }
}
