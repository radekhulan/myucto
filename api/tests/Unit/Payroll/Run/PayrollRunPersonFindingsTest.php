<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollRunPersonFindings;
use PHPUnit\Framework\TestCase;

/**
 * Příznak nálezu v přehledu osob běhu: kontrola míří na osobu přímo, přes
 * pracovní vztah, nebo seznamem dotčených u souhrnu „N osob…".
 */
final class PayrollRunPersonFindingsTest extends TestCase
{
    public function testFindingsAreCountedPerEmployeeAcrossAllWaysAValidationNamesAPerson(): void
    {
        $findings = PayrollRunPersonFindings::byEmployee(
            [
                // Přímo na osobu.
                ['severity' => 'blocker', 'entity_type' => 'employee', 'entity_id' => 7],
                // Na vztah: patří osobě, která s ním do revize vstoupila.
                ['severity' => 'warning', 'entity_type' => 'employment', 'entity_id' => 70, 'overridden_at' => null],
                // Souhrn za běh se seznamem dotčených osob.
                [
                    'severity' => 'warning',
                    'entity_type' => 'run',
                    'entity_id' => null,
                    'subject_type' => 'employee',
                    'subject_ids' => [7, 8],
                ],
                // Souhrn se seznamem dotčených vztahů.
                [
                    'severity' => 'warning',
                    'entity_type' => 'run',
                    'entity_id' => null,
                    'subject_type' => 'employment',
                    'subject_ids' => [90, 91],
                ],
            ],
            [70 => 7, 90 => 9, 91 => 9],
        );

        self::assertSame(
            [
                7 => ['blockers' => 1, 'warnings' => 2],
                8 => ['blockers' => 0, 'warnings' => 1],
                // Dva vztahy téže osoby v jednom souhrnu jsou jeden nález.
                9 => ['blockers' => 0, 'warnings' => 1],
            ],
            $findings,
        );
    }

    public function testInformationalRowsAndApprovedExceptionsAreNotFindings(): void
    {
        $findings = PayrollRunPersonFindings::byEmployee(
            [
                ['severity' => 'info', 'entity_type' => 'employee', 'entity_id' => 7],
                // Varování, u kterého už někdo schválil výjimku.
                [
                    'severity' => 'warning',
                    'entity_type' => 'employee',
                    'entity_id' => 7,
                    'overridden_at' => '2026-05-02 10:00:00',
                ],
                // Kontrola běhu bez osoby a vztah, který v revizi není.
                ['severity' => 'blocker', 'entity_type' => 'run', 'entity_id' => null],
                ['severity' => 'blocker', 'entity_type' => 'employment', 'entity_id' => 999],
            ],
            [70 => 7],
        );

        self::assertSame([], $findings);
    }
}
