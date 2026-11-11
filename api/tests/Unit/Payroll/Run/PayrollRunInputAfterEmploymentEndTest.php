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
    }
}
