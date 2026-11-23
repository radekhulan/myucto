<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\Payroll;

use MyInvoice\Service\Payroll\Document\PayslipDocumentData;
use MyInvoice\Service\Payroll\Document\PayslipHourlyDetail;
use MyInvoice\Service\Payroll\Document\PayslipLine;
use MyInvoice\Service\Payroll\Hourly\PayrollExactFraction;
use MyInvoice\Service\Payroll\Calculation\PayrollWageRounding;

final class SyntheticHourlyPayslipFixture
{
    public static function source(int $units = 455250, int $rate = 18750, int $previous = 0, int $previousUnits = 0): array
    {
        $raw = PayrollExactFraction::product([$units, $rate], [3000]);
        $payable = $raw->payableMinor(PayrollWageRounding::Ceil);
        $fraction = ['raw_numerator_minor' => $raw->numerator, 'raw_denominator' => $raw->denominator];
        return ['schema_version' => 'hourly-payslip-source.v1', 'claim_id' => 1, 'purpose' => 'ordinary_base',
            'work_period_start' => '2026-07-01', 'sequence_no' => 1, 'input_id' => 1001, 'evidence_hash' => str_repeat('a', 64),
            'previous_payable_minor' => $previous, 'previous_worked_duration_units' => $previousUnits,
            'delta_amount_minor' => $payable - $previous, 'worked_duration_delta_units' => $units - $previousUnits,
            'full_claim' => ['purpose' => 'ordinary_base', 'component_code' => 'MZDA_HODINOVA_ZAKLAD', 'ready' => true,
                'duration_units' => $units, 'worked_duration_units' => $units, ...$fraction,
                'analytical_minor' => $raw->analyticalMinor(), 'payable_minor' => $payable, 'rounding' => 'ceil',
                'segments' => [['term_id' => 1, 'date_from' => '2026-07-01', 'date_to' => '2026-07-31',
                    'duration_units' => $units, 'hourly_rate_minor' => $rate, 'monthly_gross_minor' => null, ...$fraction]]]];
    }

    public static function document(): PayslipDocumentData
    {
        $document = SyntheticPayslipFixture::document();
        $sources = [self::source(), self::source(50, 20000), self::source(492000, 20000, 3200000, 480000), self::source(300000, 18742)];
        $sources[3]['full_claim']['segments'] = [
            ['term_id' => 1, 'date_from' => '2026-07-01', 'date_to' => '2026-07-15', 'duration_units' => 150000, 'hourly_rate_minor' => 18500, 'monthly_gross_minor' => null, 'raw_numerator_minor' => 925000, 'raw_denominator' => 1],
            ['term_id' => 2, 'date_from' => '2026-07-16', 'date_to' => '2026-07-31', 'duration_units' => 150000, 'hourly_rate_minor' => 18984, 'monthly_gross_minor' => null, 'raw_numerator_minor' => 949200, 'raw_denominator' => 1],
        ];
        $labels = ['Základní mzda – analytický příklad', 'Přesná minuta', 'Oprava +4 hodiny', 'Změna sazby v měsíci'];
        $claims = $lines = [];
        foreach ($sources as $index => $source) {
            $source['claim_id'] = $index + 1;
            $source['input_id'] = 1001 + $index;
            $claims[] = PayslipHourlyDetail::claim($source, 101 + $index, $labels[$index]);
            $lines[] = new PayslipLine($labels[$index], $source['delta_amount_minor'], hourlyDetail: PayslipHourlyDetail::input($source, $source['delta_amount_minor']));
        }
        $lines[] = $document->incomeLines[1];
        return new PayslipDocumentData(...[...get_object_vars($document), 'incomeLines' => $lines, 'hourlyClaims' => $claims]);
    }
}
