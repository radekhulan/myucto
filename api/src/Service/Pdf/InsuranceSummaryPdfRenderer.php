<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF Přehledů pojistného OSVČ (sociální ČSSZ + zdravotní) — Epic DP (#18).
 *
 * Data = výstup {@see \MyInvoice\Service\Tax\Return\InsuranceSummaryService::build()}
 * doplněný o identifikaci poplatníka (klíč `supplier`). A4 portrait. Slouží jako
 * pomůcka pro vyplnění oficiálních formulářů Přehledu OSVČ ČSSZ a zdravotní pojišťovny.
 */
final class InsuranceSummaryPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('insurance_summary.twig', $data);
        $mpdf = $this->mpdf([
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 14,
        ]);
        $mpdf->SetTitle('Přehledy pojistného OSVČ ' . (string) ($data['summary']['year'] ?? ''));
        $this->withPageNumbers($mpdf, 'Přehledy pojistného OSVČ');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
