<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthBulkNotificationPayload;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationChange;

/**
 * Vytěžitelné PDF hromadného oznámení (HOZ) — vytváří se ze stejné datové
 * věty jako XML, přesně jako u přehledu o platbě
 * ({@see PayrollHealthPaymentOverviewPdfRenderer}).
 *
 * ── Proč generický vzhled, ne oficiální formulář pojišťovny ──────────────────
 * U přehledu o platbě má MyÚčto pro VZP doloženou a otestovanou oficiální
 * šablonu (`CachedHealthPaymentOverviewPdfTemplateProvider`) — soubor byl
 * ověřený, pole spočítaná a zpětně kontrolovaná. Pro HOZ takový doklad zatím
 * není: nebyl otevřený a pole žádného konkrétního formuláře pojišťovny nebyla
 * ověřená proti připnutému vzoru. Vyplnit cizí AcroForm naslepo je horší než
 * srozumitelný vlastní dokument — špatně vyplněné úřední pole by šlo poznat
 * hůř než chybějící údaj v tabulce. Až bude oficiální formulář ověřený
 * (stejným postupem jako u VZP), přibude větev jako
 * {@see PayrollHealthPaymentOverviewPdfRenderer::renderOfficialVzpForm()}.
 */
final class PayrollHealthBulkNotificationPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('payroll_health_bulk_notification.twig', $data);
        $mpdf = $this->mpdf([
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 14,
        ]);
        $mpdf->SetTitle('Hromadné oznámení zaměstnavatele');
        $mpdf->WriteHTML($body);

        return $mpdf->Output('', 'S');
    }

    public function renderPayload(
        HealthBulkNotificationPayload $payload,
        ?string $insurerName,
        string $filledOn,
    ): string {
        return $this->render([
            'insurer_code' => $payload->insurerCode,
            'insurer_name' => $insurerName,
            'employer' => $payload->employer->toArray(),
            'internal_reference' => $payload->internalReference,
            'filled_on' => $filledOn,
            'changes' => array_map(
                static fn (HealthNotificationChange $change): array => [
                    'change_code' => $change->changeCode,
                    'changed_on' => $change->changedOn,
                    'insurance_number' => $change->insuranceNumber,
                    'first_name' => $change->firstName,
                    'last_name' => $change->lastName,
                ],
                $payload->changes,
            ),
        ]);
    }

    public function templateReference(): string
    {
        return 'payroll-health-bulk-notification.v1';
    }
}
