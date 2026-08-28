<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\Submission\PayrollStatutoryAgendaCatalog;
use PHPUnit\Framework\TestCase;

final class PayrollStatutoryAgendaCatalogTest extends TestCase
{
    public function testJmhzOnlyPartiallyReplacesNempriAndNeverHzupn(): void
    {
        $matrix = (new PayrollStatutoryAgendaCatalog())->forPeriod('2026-08');
        $agendas = array_column($matrix['agendas'], null, 'agenda_code');

        self::assertSame(
            'partially_replaced',
            $agendas['NEMPRI']['replacement_mode'],
        );
        self::assertSame('manual_review', $agendas['NEMPRI']['capability']);
        self::assertTrue($agendas['NEMPRI']['evidence_supported']);
        self::assertSame('standalone', $agendas['HZUPN']['replacement_mode']);
        self::assertSame('manual_review', $agendas['HZUPN']['capability']);
        self::assertTrue($agendas['HZUPN']['evidence_supported']);
    }

    public function testAccidentInsuranceFailsClosedInsteadOfInventingCalculation(): void
    {
        $matrix = (new PayrollStatutoryAgendaCatalog())->forPeriod('2026-08');
        $agendas = array_column($matrix['agendas'], null, 'agenda_code');
        $accident = $agendas['STATUTORY_ACCIDENT_INSURANCE'];

        self::assertSame('standalone', $accident['replacement_mode']);
        self::assertSame('manual_review', $accident['capability']);
        self::assertSame('not_supported', $accident['transport_capability']);
        self::assertTrue($accident['evidence_supported']);
        self::assertSame(
            'accident_insurance_calculation_output_liability_not_supported',
            $accident['reason_code'],
        );
    }

    public function testLegacyNempriCannotBeFalselyRecordedThroughNewWorkflow(): void
    {
        $matrix = (new PayrollStatutoryAgendaCatalog())->forPeriod('2024-12');
        $agendas = array_column($matrix['agendas'], null, 'agenda_code');

        self::assertSame('not_supported', $agendas['NEMPRI']['capability']);
        self::assertFalse($agendas['NEMPRI']['evidence_supported']);
    }
}
