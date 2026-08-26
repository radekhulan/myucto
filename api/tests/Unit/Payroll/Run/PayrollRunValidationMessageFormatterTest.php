<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollRunValidationMessageFormatter;
use PHPUnit\Framework\TestCase;

final class PayrollRunValidationMessageFormatterTest extends TestCase
{
    public function testEnforcementIssueIsActionableAndDoesNotExposeInternalCode(): void
    {
        $message = PayrollRunValidationMessageFormatter::enforcement([
            'income:net_pay_result_missing_or_unverified',
        ]);

        self::assertStringContainsString('čistá mzda', $message);
        self::assertStringContainsString('Nejprve dokončete', $message);
        self::assertStringNotContainsString('net_pay_result', $message);
    }

    public function testEnforcementKeepsOtherProblemsAlongsideMissingNetPay(): void
    {
        $message = PayrollRunValidationMessageFormatter::enforcement([
            'income:net_pay_result_missing_or_unverified',
            'dependants_evidence_incomplete',
        ]);

        self::assertStringContainsString('čistá mzda', $message);
        self::assertStringContainsString('Současně v agendě Exekuce', $message);
        self::assertStringContainsString('vyživované osoby', $message);
        self::assertStringNotContainsString('dependants_evidence', $message);
        self::assertLessThanOrEqual(500, mb_strlen($message));
    }

    public function testStatutoryIssuesAreGroupedByProblemAndHideInternalIdentifiers(): void
    {
        $message = PayrollRunValidationMessageFormatter::statutory([
            'health_insurance:payroll_component_missing:employee:2:employment:3',
            'income_tax:payroll_component_missing:employee:2:employment:3',
            'social_insurance:payroll_component_missing:employee:2:employment:3',
            'income_tax:annual_accumulator_missing:employee:2',
            'social_insurance:annual_accumulator_missing:employee:2',
            'income_tax:tax_declaration_term_conflict:employee:2:employment:3',
        ]);

        self::assertStringContainsString('1 pracovního vztahu', $message);
        self::assertStringContainsString('roční součty', $message);
        self::assertStringContainsString('daňového prohlášení', $message);
        self::assertStringNotContainsString('employee:2', $message);
        self::assertStringNotContainsString('payroll_component_missing', $message);
    }

    public function testUnknownIssueIsReportedWithoutLeakingItsCode(): void
    {
        $message = PayrollRunValidationMessageFormatter::statutory([
            'some_future_internal_issue:employee:7',
        ]);

        self::assertStringContainsString('Další kontrola', $message);
        self::assertStringNotContainsString('some_future_internal_issue', $message);
    }

    public function testOtherWithholdingEligibilityNamesTheFieldToFix(): void
    {
        $message = PayrollRunValidationMessageFormatter::statutory([
            'income_tax:other-withholding-eligibility-unverified:employee:4:employment:5',
        ]);

        self::assertStringContainsString('účast na nemocenském pojištění z odměny', $message);
        self::assertStringContainsString('1 pracovního vztahu', $message);
        self::assertStringNotContainsString('other-withholding', $message);
    }

    public function testMaximumStatutoryMessageFitsStorageAndKeepsFinalInstruction(): void
    {
        $message = PayrollRunValidationMessageFormatter::statutory([
            'health_insurance:payroll_component_missing:employee:2:employment:3',
            'income_tax:annual_accumulator_missing:employee:2',
            'income_tax:tax_declaration_term_conflict:employee:2:employment:3',
            'income_tax:other-withholding-eligibility-unverified:employee:4:employment:5',
            'future:unknown:employee:9',
        ]);

        self::assertLessThanOrEqual(500, mb_strlen($message));
        self::assertStringEndsWith(
            'Opravte uvedené podklady a otevřete novou revizi mzdového běhu.',
            $message,
        );
        self::assertStringNotContainsString('future:unknown', $message);
    }
}
