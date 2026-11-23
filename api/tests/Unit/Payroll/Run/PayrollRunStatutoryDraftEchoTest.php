<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollRunValidationMessageFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Neschválený vstup dával v kontrole běhu tři hlášky jednoho problému:
 * `draft_inputs_present`, varování bez vstupů a ještě zákonné posouzení
 * „Chybí schválená mzdová složka". Poslední z nich se u vztahu, který už
 * hlásí neschválené vstupy, neopakuje. U ostatních vztahů zůstává.
 */
final class PayrollRunStatutoryDraftEchoTest extends TestCase
{
    public function testComponentMissingIsNotRepeatedForEmploymentWithDraftInputs(): void
    {
        $statutory = [
            'status' => 'manual_review',
            'issues' => [
                'social_insurance:payroll_component_missing:employee:5:employment:7',
                'income_tax:payroll_component_missing:employee:5:employment:7',
            ],
        ];

        self::assertSame([], PayrollRunValidationMessageFormatter::statutoryDetails($statutory, [7 => true]));
    }

    public function testComponentMissingStaysForEmploymentWithoutDraftInputs(): void
    {
        $statutory = [
            'status' => 'manual_review',
            'issues' => [
                'social_insurance:payroll_component_missing:employee:5:employment:7',
                'social_insurance:payroll_component_missing:employee:6:employment:8',
            ],
        ];

        $details = PayrollRunValidationMessageFormatter::statutoryDetails($statutory, [7 => true]);

        self::assertCount(1, $details);
        self::assertSame(6, $details[0]['entity_id'] ?? null);
    }

    public function testOtherReasonsOfTheSameEmploymentStay(): void
    {
        $statutory = [
            'status' => 'manual_review',
            'issues' => [
                'social_insurance:payroll_component_missing:employee:5:employment:7',
                'income_tax:annual_accumulator_missing:employee:5',
            ],
        ];

        $details = PayrollRunValidationMessageFormatter::statutoryDetails($statutory, [7 => true]);

        self::assertCount(1, $details);
        self::assertStringNotContainsString('Chybí schválená mzdová složka', (string) $details[0]['message']);
    }
}
