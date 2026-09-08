<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF obratové předvahy (A4 landscape).
 *
 * Data = výstup TrialBalanceService::build() (spec F2 §2.5) — řádky PS/obraty/KS,
 * součty a blok kontrolních rovnic (`checks`).
 */
final class TrialBalancePdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('trial_balance.twig', $data);
        $mpdf = $this->mpdf();
        $mpdf->SetTitle('Obratová předvaha ' . (string) ($data['period']['fiscal_year'] ?? ''));
        $this->withPageNumbers($mpdf, 'Obratová předvaha');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
