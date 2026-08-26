<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewPayload;

final class PayrollHealthPaymentOverviewPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $insurerCode = (string) ($data['insurer_code'] ?? '');
        $usesOwnPdfForm = in_array($insurerCode, ['209', '211'], true);
        $body = $this->renderTemplate(
            $usesOwnPdfForm
                ? 'payroll_health_payment_overview_insurer_form.twig'
                : 'payroll_health_payment_overview.twig',
            $data,
        );
        $mpdf = $this->mpdf([
            'format' => $usesOwnPdfForm ? 'A5' : 'A4',
            'orientation' => $usesOwnPdfForm ? 'L' : 'P',
            'margin_left' => $usesOwnPdfForm ? 9 : 14,
            'margin_right' => $usesOwnPdfForm ? 9 : 14,
            'margin_top' => $usesOwnPdfForm ? 8 : 14,
            'margin_bottom' => $usesOwnPdfForm ? 8 : 14,
        ]);
        $mpdf->SetTitle('Přehled o platbě pojistného zaměstnavatele');
        $mpdf->WriteHTML($body);

        return $mpdf->Output('', 'S');
    }

    public function renderPayload(
        HealthPaymentOverviewPayload $payload,
        ?string $insurerName,
        string $filledOn,
    ): string {
        return $this->render([
            'insurer_code' => $payload->insurerCode,
            'insurer_name' => $insurerName,
            'overview_kind' => $payload->overviewKind,
            'period' => $payload->period(),
            'month' => $payload->month,
            'year' => $payload->year,
            'employee_count' => $payload->employeeCount,
            'assessment_base' => $payload->assessmentBaseDecimal(),
            'contribution_czk' => $payload->contributionCzk,
            'internal_reference' => $payload->internalReference,
            'filled_on' => $filledOn,
            'insurer_short_name' => match ($payload->insurerCode) {
                '209' => 'ZPŠ',
                '211' => 'ZP MV ČR',
                default => $insurerName,
            },
            'employer' => $payload->employer->toArray(),
        ]);
    }
}
