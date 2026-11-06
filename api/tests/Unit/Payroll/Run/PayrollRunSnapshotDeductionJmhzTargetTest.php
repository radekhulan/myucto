<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Věřitel a zařazení srážky pro JMHZ (10349 až 10353) jdou do zmrazeného vstupu
 * mzdového běhu jen u rozhodnuté dohody. Nerozhodnutá dohoda a všechny starší
 * snímky zůstávají bajtově stejné; JmhzOrdinaryEvidenceBuilder nerozhodnutou
 * dohodu pozná podle chybějícího klíče.
 */
final class PayrollRunSnapshotDeductionJmhzTargetTest extends TestCase
{
    public function testClassificationIsFrozenOnlyForDecidedAgreements(): void
    {
        $rows = [
            $this->row(5, null, null),
            $this->row(6, 1, '10352'),
            $this->row(7, 0, null),
        ];
        $builder = (new \ReflectionClass(PayrollRunSnapshotBuilder::class))->newInstanceWithoutConstructor();
        $frozen = (new \ReflectionMethod(PayrollRunSnapshotBuilder::class, 'deductionAgreements'))
            ->invoke($builder, $rows);

        self::assertArrayNotHasKey('creditor_is_employer', $frozen[0]);
        self::assertArrayNotHasKey('jmhz_target', $frozen[0]);
        self::assertTrue($frozen[1]['creditor_is_employer']);
        self::assertSame('10352', $frozen[1]['jmhz_target']);
        self::assertFalse($frozen[2]['creditor_is_employer']);
        self::assertArrayNotHasKey('jmhz_target', $frozen[2]);
    }

    /** @return array<string,mixed> */
    private function row(int $id, ?int $creditor, ?string $target): array
    {
        return [
            'id' => $id,
            'agreement_reference' => "srazka-{$id}",
            'title' => 'Syntetická srážka',
            'deduction_kind' => 'meal',
            'legal_basis' => 'agreement',
            'creditor_is_employer' => $creditor,
            'jmhz_target' => $target,
            'priority_no' => 100,
            'requested_minor' => 10_000,
            'total_limit_minor' => null,
            'withheld_total_minor' => 0,
            'taken_over_withheld_minor' => 0,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'delivered_on' => null,
            'row_version' => 1,
        ];
    }
}
