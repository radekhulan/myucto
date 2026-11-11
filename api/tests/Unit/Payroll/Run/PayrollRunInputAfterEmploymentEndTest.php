<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Vztah ukončený před obdobím, který do běhu vstoupil jen kvůli vstupu za
 * období, musí kontrola pojmenovat: měsíc vstupu, den konce a odkaz na vstupy
 * vztahu. Potvrzený odložený příjem (doplatek po skončení) je v pořádku.
 */
final class PayrollRunInputAfterEmploymentEndTest extends TestCase
{
    public function testEndedEmploymentWithInputAfterEndIsNamed(): void
    {
        $validations = PayrollRunSnapshotBuilder::inputsAfterEmploymentEndValidations([
            ['employment_id' => 11, 'full_name' => 'Lednová Dohoda', 'end_date' => '2026-01-31'],
            ['employment_id' => 12, 'full_name' => 'Trvající Dohoda', 'end_date' => null],
            ['employment_id' => 13, 'full_name' => 'Končí V Únoru', 'end_date' => '2026-02-01'],
            ['employment_id' => 14, 'full_name' => 'Doplatek', 'end_date' => '2025-12-31'],
        ], [14 => ['deferred_type' => 'post_termination_income']], '2026-02-01');

        self::assertCount(1, $validations);
        $validation = $validations[0];
        self::assertSame('blocker', $validation->severity);
        self::assertSame('input_after_employment_end', $validation->code);
        self::assertSame(11, $validation->entityId);
        self::assertStringContainsString('vstup za 02/2026 je na vztahu ukončeném 31. 1. 2026', $validation->message);
        self::assertStringStartsWith('Lednová Dohoda:', $validation->message);
        self::assertSame('/payroll/components?tab=inputs&period=2026-02&employment=11', $validation->remediationPath);
        self::assertStringContainsString('zadejte na trvající vztah', $validation->message);
    }

    public function testEndedEmploymentNamesValidEmploymentOfTheSamePerson(): void
    {
        $validations = PayrollRunSnapshotBuilder::inputsAfterEmploymentEndValidations([
            ['employment_id' => 11, 'employee_id' => 5, 'full_name' => 'Syntetická Brigádnice', 'end_date' => '2026-01-31', 'relation_type' => 'dpp', 'employment_code' => '1', 'start_date' => '2026-01-05'],
            ['employment_id' => 12, 'employee_id' => 5, 'full_name' => 'Syntetická Brigádnice', 'end_date' => '2026-02-28', 'relation_type' => 'dpp', 'employment_code' => '2', 'start_date' => '2026-02-07', 'actual_start_date' => '2026-02-07'],
            ['employment_id' => 13, 'employee_id' => 6, 'full_name' => 'Jiná Osoba', 'end_date' => null, 'relation_type' => 'dpp', 'employment_code' => '1', 'start_date' => '2026-01-01'],
        ], [], '2026-02-01');

        self::assertCount(1, $validations);
        self::assertStringContainsString('přesuňte (Upravit, Pracovní vztah) na platný vztah téže osoby DPP 2 od 7. 2. 2026 do 28. 2. 2026', $validations[0]->message);
        self::assertStringNotContainsString('Jiná', $validations[0]->message);
    }

    public function testValidEmploymentsOfPersonSkipEndedAndOtherPeople(): void
    {
        $rows = [
            ['employment_id' => 1, 'employee_id' => 5, 'end_date' => '2026-01-31', 'relation_type' => 'dpp', 'employment_code' => 'A', 'start_date' => '2026-01-01'],
            ['employment_id' => 2, 'employee_id' => 5, 'end_date' => null, 'relation_type' => 'employment', 'employment_code' => 'B', 'start_date' => '2026-02-20'],
            ['employment_id' => 3, 'employee_id' => 7, 'end_date' => null, 'relation_type' => 'dpp', 'employment_code' => 'C', 'start_date' => '2026-02-01'],
        ];
        self::assertSame('pracovní poměr B od 20. 2. 2026', PayrollRunSnapshotBuilder::validEmploymentsOfPerson($rows, 5, 9, '2026-02-01'));
        self::assertNull(PayrollRunSnapshotBuilder::validEmploymentsOfPerson($rows, 5, 2, '2026-02-01'));
        self::assertNull(PayrollRunSnapshotBuilder::validEmploymentsOfPerson($rows, 0, 2, '2026-02-01'));
    }
}
